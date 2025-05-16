from aws_cdk import (
    Stack,
    aws_ec2 as ec2,
    aws_rds as rds,
    aws_secretsmanager as secretsmanager,
    aws_route53 as route53,
    aws_route53_targets as route53_targets,
    CfnOutput,
    RemovalPolicy,
    Duration
)
from constructs import Construct

class DatabaseStack(Stack):
    def __init__(self, scope: Construct, construct_id: str,
                 vpc: ec2.IVpc,
                 database_security_group: ec2.ISecurityGroup,
                 app_name: str,
                 private_hosted_zone_id: str = None,
                 public_hosted_zone_id: str = None,
                 domain_name: str = None,
                 db_subdomain: str = "db",
                 **kwargs) -> None:
        super().__init__(scope, construct_id, **kwargs)

        # Database name derived from app name
        db_name = f"{app_name.replace('-', '_')}_db"
        
        # Create a secret with fixed credentials for the prototype phase
        db_credentials = secretsmanager.Secret(self, "DBCredentialsSecret",
            secret_name=f"{app_name}-db-credentials",
            description="Hardcoded credentials for prototype database access",
            generate_secret_string=secretsmanager.SecretStringGenerator(
                secret_string_template='{"username": "admin"}',
                generate_string_key="password",
                exclude_punctuation=False,
                exclude_characters="",
                password_length=16,
                require_each_included_type=True,
                include_space=False
            )
        )
        
        # Override the generated password with the hardcoded one
        cfn_secret = db_credentials.node.default_child
        cfn_secret.add_override("Properties.GenerateSecretString", {
            "SecretStringTemplate": '{"username": "admin"}',
            "GenerateStringKey": "password",
            "PasswordLength": 16,
            "ExcludePunctuation": False
        })
        cfn_secret.add_override("Properties.SecretString", '{"username": "admin", "password": "Thx11381!"}')

        # RDS Aurora Serverless v2 MySQL Cluster with termination protection
        self.db_cluster = rds.DatabaseCluster(self, "AppDatabaseCluster",
            engine=rds.DatabaseClusterEngine.aurora_mysql(
                version=rds.AuroraMysqlEngineVersion.VER_3_04_0  # MySQL 8.0.28 compatible
            ),
            credentials=rds.Credentials.from_secret(db_credentials), # Use our hardcoded credentials
            writer=rds.ClusterInstance.serverless_v2("writerInstance"), # Defines a Serverless v2 writer instance
            serverless_v2_min_capacity=0.5, # Min ACUs
            serverless_v2_max_capacity=1.0, # Max ACUs
            vpc=vpc,
            vpc_subnets=ec2.SubnetSelection(subnet_type=ec2.SubnetType.PRIVATE_WITH_EGRESS),
            security_groups=[database_security_group],
            default_database_name=db_name,
            removal_policy=RemovalPolicy.RETAIN,  # Changed to RETAIN to prevent accidental deletion
            deletion_protection=True,  # Enable termination protection
            backup=rds.BackupProps(
                retention=Duration.days(7)  # Increased backup retention for better recovery options
            )
        )

        # Create a custom domain string for the database
        custom_domain = f"{db_subdomain}.{domain_name}" if domain_name and db_subdomain else None
        
        # Create a more complete secret for Laravel with both the actual endpoint and the custom domain
        # Note: Secret will need a dependency on Route53 record creation before using custom domain
        final_credentials_json = {
            "username": "admin",
            "password": "Thx11381!",
            "host": self.db_cluster.cluster_endpoint.hostname,
            "port": "3306",
            "dbname": db_name,
            "custom_host": custom_domain
        }
        
        # Create a formatted JSON string
        json_string = '{' + \
            f'"username":"admin",' + \
            f'"password":"Thx11381!",' + \
            f'"host":"{self.db_cluster.cluster_endpoint.hostname}",' + \
            f'"port":"3306",' + \
            f'"dbname":"{db_name}",' + \
            f'"custom_host":"{custom_domain if custom_domain else ""}"' + \
            '}'
            
        # Override with complete JSON
        cfn_secret.add_override("Properties.SecretString", json_string)

        # Export the DB cluster secret for other stacks to use
        self.db_cluster_secret = db_credentials

        # Add a comment to indicate these are hardcoded credentials for prototype only
        CfnOutput(self, "PrototypeWarningOutput",
            value="WARNING: Using hardcoded credentials for prototype only. Change for production!",
            description="Warning about hardcoded credentials"
        )
        
        # Create custom DNS records for the database in both private and public hosted zones
        self.db_custom_endpoint = None
        
        # Function to create DNS record in a hosted zone
        def create_dns_record(hosted_zone_id, zone_type):
            if hosted_zone_id and domain_name:
                # Look up the hosted zone by ID and zone name (using fromHostedZoneAttributes instead of fromHostedZoneId)
                hosted_zone = route53.HostedZone.from_hosted_zone_attributes(
                    self, 
                    f"Imported{zone_type}HostedZone", 
                    hosted_zone_id=hosted_zone_id,
                    zone_name=domain_name
                )
                
                # Create the full database domain name
                full_db_domain = f"{db_subdomain}.{domain_name}"
                
                # Create a CNAME record pointing to the database endpoint
                db_record = route53.CnameRecord(
                    self,
                    f"Database{zone_type}CnameRecord",
                    zone=hosted_zone,
                    record_name=db_subdomain,
                    domain_name=self.db_cluster.cluster_endpoint.hostname,
                    ttl=Duration.minutes(5)  # Short TTL for prototype
                )
                
                self.db_custom_endpoint = full_db_domain
                
                # Output the custom domain endpoint for this zone
                CfnOutput(self, f"{zone_type}CustomDatabaseEndpoint",
                    value=full_db_domain,
                    description=f"Custom domain name for database connection in {zone_type} zone",
                    export_name=f"{app_name}-{zone_type.lower()}-custom-db-endpoint"
                )
        
        # Create records in both hosted zones if provided
        if private_hosted_zone_id:
            create_dns_record(private_hosted_zone_id, "Private")
            
        if public_hosted_zone_id:
            create_dns_record(public_hosted_zone_id, "Public")
        
        # Outputs for other stacks to reference
        CfnOutput(self, "DbCredentialsOutput",
            value="Username: admin, Password: Thx11381! (For prototype only, do not use in production)",
            description="Hardcoded database credentials for prototype use"
        )
        
        CfnOutput(self, "DbClusterEndpointOutput",
            value=self.db_cluster.cluster_endpoint.hostname,
            description="Hostname of the DB Cluster Endpoint",
            export_name=f"{app_name}-db-cluster-endpoint"
        )
        
        CfnOutput(self, "DbClusterReadEndpointOutput",
            value=self.db_cluster.cluster_read_endpoint.hostname,
            description="Hostname of the DB Cluster Read Endpoint",
            export_name=f"{app_name}-db-cluster-read-endpoint"
        )
        
        CfnOutput(self, "DbClusterSecretArnOutput",
            value=db_credentials.secret_arn,
            description="ARN of the DB Cluster master credentials secret in Secrets Manager",
            export_name=f"{app_name}-db-cluster-secret-arn"
        ) 