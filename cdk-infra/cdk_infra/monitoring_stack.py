from aws_cdk import (
    Stack,
    aws_cloudwatch as cloudwatch,
    aws_cloudwatch_actions as cw_actions,
    aws_sns as sns,
    aws_iam as iam,
    aws_lambda as lambda_,
    aws_events as events,
    aws_events_targets as targets,
    Duration,
    RemovalPolicy,
    CfnOutput
)
from constructs import Construct

class MonitoringStack(Stack):
    def __init__(self, scope: Construct, construct_id: str,
                 audio_extraction_queue,
                 transcription_queue,
                 terminology_queue,
                 callback_queue,
                 **kwargs) -> None:
        super().__init__(scope, construct_id, **kwargs)

        app_name = "aws-transcription"

        # Create a CloudWatch Dashboard for the Transcription Service
        dashboard = cloudwatch.Dashboard(self, "TranscriptionServiceDashboard",
            dashboard_name=f"{app_name}-service-dashboard",
        )

        # Create queue length widgets
        queue_metrics_widget = cloudwatch.GraphWidget(
            title="SQS Queue Lengths",
            left=[
                audio_extraction_queue.metric_approximate_number_of_messages_visible(
                    label="Audio Extraction Queue",
                    period=Duration.minutes(1),
                ),
                transcription_queue.metric_approximate_number_of_messages_visible(
                    label="Transcription Queue",
                    period=Duration.minutes(1),
                ),
                terminology_queue.metric_approximate_number_of_messages_visible(
                    label="Terminology Queue",
                    period=Duration.minutes(1),
                ),
                callback_queue.metric_approximate_number_of_messages_visible(
                    label="Callback Queue",
                    period=Duration.minutes(1),
                ),
            ],
            width=24
        )

        # ECS Service metrics for each service
        ecs_metrics_widget = cloudwatch.GraphWidget(
            title="ECS Service Task Counts",
            left=[
                cloudwatch.Metric(
                    namespace="AWS/ECS",
                    metric_name="RunningTaskCount",
                    dimensions_map={
                        "ServiceName": f"{app_name}-audio-extraction-service",
                        "ClusterName": f"{app_name}-cluster"
                    },
                    label="Audio Extraction Tasks",
                    period=Duration.minutes(1),
                ),
                cloudwatch.Metric(
                    namespace="AWS/ECS",
                    metric_name="RunningTaskCount",
                    dimensions_map={
                        "ServiceName": f"{app_name}-transcription-service",
                        "ClusterName": f"{app_name}-cluster"
                    },
                    label="Transcription Tasks",
                    period=Duration.minutes(1),
                ),
                cloudwatch.Metric(
                    namespace="AWS/ECS",
                    metric_name="RunningTaskCount",
                    dimensions_map={
                        "ServiceName": f"{app_name}-terminology-service",
                        "ClusterName": f"{app_name}-cluster"
                    },
                    label="Terminology Tasks",
                    period=Duration.minutes(1),
                ),
                cloudwatch.Metric(
                    namespace="AWS/ECS",
                    metric_name="RunningTaskCount",
                    dimensions_map={
                        "ServiceName": f"{app_name}-laravel-service",
                        "ClusterName": f"{app_name}-cluster"
                    },
                    label="Laravel Tasks",
                    period=Duration.minutes(1),
                ),
            ],
            width=24
        )

        # CPU and Memory utilization for each service
        cpu_widget = cloudwatch.GraphWidget(
            title="CPU Utilization by Service",
            left=[
                cloudwatch.Metric(
                    namespace="AWS/ECS",
                    metric_name="CPUUtilization",
                    dimensions_map={
                        "ServiceName": f"{app_name}-audio-extraction-service",
                        "ClusterName": f"{app_name}-cluster"
                    },
                    label="Audio Extraction CPU",
                    period=Duration.minutes(1),
                    statistic="Average"
                ),
                cloudwatch.Metric(
                    namespace="AWS/ECS",
                    metric_name="CPUUtilization",
                    dimensions_map={
                        "ServiceName": f"{app_name}-transcription-service",
                        "ClusterName": f"{app_name}-cluster"
                    },
                    label="Transcription CPU",
                    period=Duration.minutes(1),
                    statistic="Average"
                ),
                cloudwatch.Metric(
                    namespace="AWS/ECS",
                    metric_name="CPUUtilization",
                    dimensions_map={
                        "ServiceName": f"{app_name}-terminology-service",
                        "ClusterName": f"{app_name}-cluster"
                    },
                    label="Terminology CPU",
                    period=Duration.minutes(1),
                    statistic="Average"
                ),
                cloudwatch.Metric(
                    namespace="AWS/ECS",
                    metric_name="CPUUtilization",
                    dimensions_map={
                        "ServiceName": f"{app_name}-laravel-service",
                        "ClusterName": f"{app_name}-cluster"
                    },
                    label="Laravel CPU",
                    period=Duration.minutes(1),
                    statistic="Average"
                ),
            ],
            width=12
        )

        memory_widget = cloudwatch.GraphWidget(
            title="Memory Utilization by Service",
            left=[
                cloudwatch.Metric(
                    namespace="AWS/ECS",
                    metric_name="MemoryUtilization",
                    dimensions_map={
                        "ServiceName": f"{app_name}-audio-extraction-service",
                        "ClusterName": f"{app_name}-cluster"
                    },
                    label="Audio Extraction Memory",
                    period=Duration.minutes(1),
                    statistic="Average"
                ),
                cloudwatch.Metric(
                    namespace="AWS/ECS",
                    metric_name="MemoryUtilization",
                    dimensions_map={
                        "ServiceName": f"{app_name}-transcription-service",
                        "ClusterName": f"{app_name}-cluster"
                    },
                    label="Transcription Memory",
                    period=Duration.minutes(1),
                    statistic="Average"
                ),
                cloudwatch.Metric(
                    namespace="AWS/ECS",
                    metric_name="MemoryUtilization",
                    dimensions_map={
                        "ServiceName": f"{app_name}-terminology-service",
                        "ClusterName": f"{app_name}-cluster"
                    },
                    label="Terminology Memory",
                    period=Duration.minutes(1),
                    statistic="Average"
                ),
                cloudwatch.Metric(
                    namespace="AWS/ECS",
                    metric_name="MemoryUtilization",
                    dimensions_map={
                        "ServiceName": f"{app_name}-laravel-service",
                        "ClusterName": f"{app_name}-cluster"
                    },
                    label="Laravel Memory",
                    period=Duration.minutes(1),
                    statistic="Average"
                ),
            ],
            width=12
        )

        # Job costs widget - updated to show actual cost data
        job_costs_widget = cloudwatch.GraphWidget(
            title="Job Costs",
            left=[
                cloudwatch.Metric(
                    namespace="TranscriptionServiceCosts",
                    metric_name="TotalCost",
                    statistic="Average",
                    period=Duration.hours(1),
                ),
                cloudwatch.Metric(
                    namespace="TranscriptionServiceCosts",
                    metric_name="ComputeCost",
                    statistic="Average",
                    period=Duration.hours(1),
                ),
                cloudwatch.Metric(
                    namespace="TranscriptionServiceCosts",
                    metric_name="StorageCost",
                    statistic="Average",
                    period=Duration.hours(1),
                ),
                cloudwatch.Metric(
                    namespace="TranscriptionServiceCosts",
                    metric_name="NetworkCost",
                    statistic="Average",
                    period=Duration.hours(1),
                ),
            ],
            width=24
        )

        # Lambda function for cost tracking
        cost_tracker_role = iam.Role(self, "CostTrackerRole",
            assumed_by=iam.ServicePrincipal("lambda.amazonaws.com"),
            managed_policies=[
                iam.ManagedPolicy.from_aws_managed_policy_name("service-role/AWSLambdaBasicExecutionRole")
            ]
        )
        
        # Add CloudWatch and Cost Explorer permissions
        cost_tracker_role.add_to_policy(iam.PolicyStatement(
            actions=[
                "cloudwatch:GetMetricData",
                "cloudwatch:GetMetricStatistics",
                "cloudwatch:PutMetricData",
                "logs:CreateLogGroup",
                "logs:CreateLogStream",
                "logs:PutLogEvents"
            ],
            resources=["*"]
        ))
        
        cost_tracker_role.add_to_policy(iam.PolicyStatement(
            actions=[
                "ce:GetCostAndUsage"
            ],
            resources=["*"]
        ))
        
        # Create the Lambda function
        cost_tracker_lambda = lambda_.Function(self, "CostTrackerFunction",
            runtime=lambda_.Runtime.PYTHON_3_9,
            handler="cost_tracker.lambda_handler",
            code=lambda_.Code.from_asset("lambda"),
            role=cost_tracker_role,
            timeout=Duration.minutes(3),
            memory_size=256,
            environment={
                "NAMESPACE": "TranscriptionService",
                "COST_METRICS_NAMESPACE": "TranscriptionServiceCosts"
            }
        )
        
        # Schedule to run every 6 hours
        cost_tracker_schedule = events.Rule(self, "CostTrackerSchedule",
            schedule=events.Schedule.rate(Duration.hours(6)),
            targets=[targets.LambdaFunction(cost_tracker_lambda)]
        )

        # Add all widgets to the dashboard
        dashboard.add_widgets(
            queue_metrics_widget,
            ecs_metrics_widget,
            cpu_widget, memory_widget,
            job_costs_widget
        )

        # Output the dashboard URL
        CfnOutput(self, "DashboardURL",
            value=f"https://{self.region}.console.aws.amazon.com/cloudwatch/home?region={self.region}#dashboards:name={app_name}-service-dashboard",
            description="URL for the CloudWatch Dashboard"
        ) 