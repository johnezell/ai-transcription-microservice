# TFS AI Infrastructure Makefile
# Provides consistent interface for Terraform operations using Docker

# Variables
TERRAFORM_VERSION := 1.7.0
DOCKER_IMAGE := hashicorp/terraform:$(TERRAFORM_VERSION)
PROJECT_ROOT := $(shell pwd)
TERRAFORM_DIR := $(PROJECT_ROOT)/terraform
AWS_DIR := ~/.aws
TIMESTAMP := $(shell date +%Y%m%d-%H%M%S)

# Environment variables
ENV ?= staging
AWS_PROFILE_STAGING := tfs-ai-terraform
AWS_PROFILE_PRODUCTION := tfs-ai-terraform
AWS_PROFILE_DEV := tfs-ai-terraform

# Color output
RED := \033[0;31m
GREEN := \033[0;32m
YELLOW := \033[1;33m
NC := \033[0m # No Color

# Default target
.DEFAULT_GOAL := help

# =============================================================================
# LOCAL DEVELOPMENT
# =============================================================================

.PHONY: local local-stop local-logs local-dev local-build local-shell

local: ## Start local Laravel (http://localhost:8080) - uses pre-built assets
	@echo "$(GREEN)Starting Thoth locally...$(NC)"
	@docker-compose -f docker-compose.local.yml up -d --build
	@echo "$(GREEN)✅ Thoth is running at http://localhost:8080$(NC)"
	@echo "$(YELLOW)Note: Using pre-built assets. Run 'make local-dev' for hot-reload.$(NC)"

local-dev: ## Start with Vite hot-reload for UI development
	@echo "$(GREEN)Starting Thoth with Vite hot-reload...$(NC)"
	@docker-compose -f docker-compose.local.yml --profile dev up -d --build
	@echo "$(GREEN)✅ Thoth is running:$(NC)"
	@echo "   - Laravel: http://localhost:8080"
	@echo "   - Vite:    http://localhost:3005"
	@echo "$(YELLOW)Frontend changes will hot-reload automatically!$(NC)"

local-stop: ## Stop all local services
	@docker-compose -f docker-compose.local.yml --profile dev down

local-logs: ## View local Laravel logs
	@docker-compose -f docker-compose.local.yml logs -f

local-logs-vite: ## View Vite dev server logs
	@docker-compose -f docker-compose.local.yml logs -f vite

local-build: ## Build frontend assets for production-like testing
	@echo "$(GREEN)Building frontend assets...$(NC)"
	@docker-compose -f docker-compose.local.yml exec laravel npm install
	@docker-compose -f docker-compose.local.yml exec laravel npm run build
	@echo "$(GREEN)✅ Frontend assets built$(NC)"

local-shell: ## Open shell in Laravel container
	@docker-compose -f docker-compose.local.yml exec laravel bash

local-npm: ## Run npm command in container (usage: make local-npm CMD="install axios")
ifndef CMD
	@echo "$(RED)Error: CMD not specified$(NC)"
	@echo "Usage: make local-npm CMD=\"install axios\""
	@exit 1
endif
	@docker-compose -f docker-compose.local.yml exec laravel npm $(CMD)

# =============================================================================
# TESTING
# =============================================================================

.PHONY: test test-unit test-feature test-coverage test-filter

test: ## Run all tests locally (in Docker)
	@echo "$(GREEN)Running all tests...$(NC)"
	@docker-compose -f docker-compose.local.yml exec laravel php artisan test --parallel
	@echo "$(GREEN)✅ Tests completed$(NC)"

test-unit: ## Run unit tests only
	@echo "$(GREEN)Running unit tests...$(NC)"
	@docker-compose -f docker-compose.local.yml exec laravel php artisan test --testsuite=Unit
	@echo "$(GREEN)✅ Unit tests completed$(NC)"

test-feature: ## Run feature tests only
	@echo "$(GREEN)Running feature tests...$(NC)"
	@docker-compose -f docker-compose.local.yml exec laravel php artisan test --testsuite=Feature
	@echo "$(GREEN)✅ Feature tests completed$(NC)"

test-coverage: ## Run tests with coverage report
	@echo "$(GREEN)Running tests with coverage...$(NC)"
	@docker-compose -f docker-compose.local.yml exec laravel php artisan test --coverage
	@echo "$(GREEN)✅ Coverage report generated$(NC)"

test-filter: ## Run specific test (usage: make test-filter FILTER=testname)
ifndef FILTER
	@echo "$(RED)Error: FILTER not specified$(NC)"
	@echo "Usage: make test-filter FILTER=test_can_list_articles"
	@exit 1
endif
	@echo "$(GREEN)Running tests matching: $(FILTER)...$(NC)"
	@docker-compose -f docker-compose.local.yml exec laravel php artisan test --filter=$(FILTER)

test-articles: ## Run article-related tests only
	@echo "$(GREEN)Running article tests...$(NC)"
	@docker-compose -f docker-compose.local.yml exec laravel php artisan test --filter=Article
	@echo "$(GREEN)✅ Article tests completed$(NC)"

test-youtube: ## Run YouTube article tests (mocked)
	@echo "$(GREEN)Running YouTube article tests...$(NC)"
	@docker-compose -f docker-compose.local.yml exec laravel php artisan test --filter=YouTubeArticleTest
	@echo "$(GREEN)✅ YouTube tests completed$(NC)"

test-youtube-e2e: ## Run YouTube REAL E2E tests (hits YouTube API)
	@echo "$(GREEN)Running YouTube E2E tests (real API calls)...$(NC)"
	@docker-compose -f docker-compose.local.yml exec laravel php artisan test --filter=YouTubeE2ETest
	@echo "$(GREEN)✅ YouTube E2E tests completed$(NC)"

test-youtube-full: ## Run full YouTube E2E with Bedrock generation
	@echo "$(GREEN)Running full YouTube E2E tests (includes Bedrock)...$(NC)"
	@docker-compose -f docker-compose.local.yml exec laravel php artisan test --filter=YouTubeE2ETest --group=bedrock
	@echo "$(GREEN)✅ Full E2E tests completed$(NC)"

test-fresh: ## Run tests with fresh database
	@echo "$(GREEN)Running tests with fresh database...$(NC)"
	@docker-compose -f docker-compose.local.yml exec laravel php artisan migrate:fresh --env=testing
	@docker-compose -f docker-compose.local.yml exec laravel php artisan test
	@echo "$(GREEN)✅ Tests completed$(NC)"

# =============================================================================
# E2E BROWSER TESTS (Playwright)
# =============================================================================

.PHONY: test-e2e test-e2e-ui test-e2e-headed test-e2e-debug test-e2e-report

test-e2e: ## Run E2E browser tests (requires local server running)
	@echo "$(GREEN)Running E2E browser tests with Playwright...$(NC)"
	@echo "$(YELLOW)Note: Make sure local server is running (make local or make local-dev)$(NC)"
	@cd app/laravel && npm run test:e2e
	@echo "$(GREEN)✅ E2E tests completed$(NC)"

test-e2e-ui: ## Run E2E tests with interactive UI
	@echo "$(GREEN)Opening Playwright Test UI...$(NC)"
	@cd app/laravel && npm run test:e2e:ui

test-e2e-headed: ## Run E2E tests in headed browser (visible)
	@echo "$(GREEN)Running E2E tests in headed mode...$(NC)"
	@cd app/laravel && npm run test:e2e:headed

test-e2e-debug: ## Run E2E tests in debug mode
	@echo "$(GREEN)Running E2E tests in debug mode...$(NC)"
	@cd app/laravel && npm run test:e2e:debug

test-e2e-report: ## Show last E2E test report
	@echo "$(GREEN)Opening E2E test report...$(NC)"
	@cd app/laravel && npm run test:e2e:report

test-e2e-brand: ## Run brand selection E2E tests
	@echo "$(GREEN)Running brand selection E2E tests...$(NC)"
	@cd app/laravel && npx playwright test brand-selection

test-e2e-articles: ## Run articles index E2E tests
	@echo "$(GREEN)Running articles index E2E tests...$(NC)"
	@cd app/laravel && npx playwright test articles-index

test-e2e-create: ## Run create article E2E tests
	@echo "$(GREEN)Running create article E2E tests...$(NC)"
	@cd app/laravel && npx playwright test create-article

test-e2e-settings: ## Run settings page E2E tests
	@echo "$(GREEN)Running settings E2E tests...$(NC)"
	@cd app/laravel && npx playwright test settings

test-e2e-flow: ## Run full navigation flow E2E tests
	@echo "$(GREEN)Running navigation flow E2E tests...$(NC)"
	@cd app/laravel && npx playwright test navigation-flow

# Validate environment parameter
.PHONY: check-env
check-env:
ifndef ENV
	@echo "$(RED)Error: ENV not specified$(NC)"
	@echo "Usage: make <command> ENV=<environment>"
	@echo "Available environments: dev, staging, production"
	@exit 1
endif
	@if [ "$(ENV)" != "dev" ] && [ "$(ENV)" != "staging" ] && [ "$(ENV)" != "production" ]; then \
		echo "$(RED)Error: Invalid environment '$(ENV)'$(NC)"; \
		echo "Available environments: dev, staging, production"; \
		exit 1; \
	fi

# Load environment variables
.PHONY: load-env
load-env: check-env
	@echo "$(GREEN)Loading environment: $(ENV)$(NC)"
	@if [ -f ".env.$(ENV)" ]; then \
		export $$(cat .env.$(ENV) | grep -v '^#' | xargs); \
	else \
		echo "$(RED)Error: .env.$(ENV) file not found$(NC)"; \
		exit 1; \
	fi

# Docker run helper
define docker_run
	@if [ -t 0 ]; then \
		docker run --rm -it \
			-v $(TERRAFORM_DIR):/terraform \
			-v $(AWS_DIR):/root/.aws:ro \
			-v $(PROJECT_ROOT)/.env.$(ENV):/terraform/.env:ro \
			-w /terraform \
			-e AWS_PROFILE=$(AWS_PROFILE_$(shell echo $(ENV) | tr a-z A-Z)) \
			-e TF_VAR_environment=$(ENV) \
			$(DOCKER_IMAGE) $(1); \
	else \
		docker run --rm \
			-v $(TERRAFORM_DIR):/terraform \
			-v $(AWS_DIR):/root/.aws:ro \
			-v $(PROJECT_ROOT)/.env.$(ENV):/terraform/.env:ro \
			-w /terraform \
			-e AWS_PROFILE=$(AWS_PROFILE_$(shell echo $(ENV) | tr a-z A-Z)) \
			-e TF_VAR_environment=$(ENV) \
			$(DOCKER_IMAGE) $(1); \
	fi
endef

# Initialize Terraform
.PHONY: init
init:
	@echo "$(GREEN)Initializing Terraform...$(NC)"
	@mkdir -p $(TERRAFORM_DIR)/.terraform
	$(call docker_run,init -upgrade)

# Format Terraform files
.PHONY: fmt
fmt:
	@echo "$(GREEN)Formatting Terraform files...$(NC)"
	$(call docker_run,fmt -recursive)

# Validate Terraform configuration
.PHONY: validate
validate:
	@echo "$(GREEN)Validating Terraform configuration...$(NC)"
	$(call docker_run,validate)

# Plan infrastructure changes
.PHONY: plan
plan: check-env validate
	@echo "$(GREEN)Planning infrastructure for $(ENV)...$(NC)"
	$(call docker_run,plan -var-file=environments/$(ENV).tfvars -out=tfplan-$(ENV).out)

# Apply infrastructure changes
.PHONY: apply
apply: check-env
	@echo "$(YELLOW)Applying infrastructure changes for $(ENV)...$(NC)"
	@if [ ! -f "$(TERRAFORM_DIR)/tfplan-$(ENV).out" ]; then \
		echo "$(RED)Error: No plan file found. Run 'make plan ENV=$(ENV)' first$(NC)"; \
		exit 1; \
	fi
	$(call docker_run,apply tfplan-$(ENV).out)

# Destroy infrastructure (with confirmation)
.PHONY: destroy
destroy: check-env
	@echo "$(RED)WARNING: This will destroy all infrastructure in $(ENV)!$(NC)"
	@echo -n "Type 'destroy-$(ENV)' to confirm: "
	@read confirm; \
	if [ "$$confirm" = "destroy-$(ENV)" ]; then \
		$(call docker_run,destroy -var-file=environments/$(ENV).tfvars -auto-approve); \
	else \
		echo "$(GREEN)Destroy cancelled$(NC)"; \
	fi

# Show current state
.PHONY: show
show: check-env
	@echo "$(GREEN)Current infrastructure state for $(ENV):$(NC)"
	$(call docker_run,show)

# List resources
.PHONY: list
list: check-env
	@echo "$(GREEN)Resources in $(ENV):$(NC)"
	$(call docker_run,state list)

# Output values
.PHONY: output
output: check-env
	@echo "$(GREEN)Output values for $(ENV):$(NC)"
	$(call docker_run,output -json)

# Target specific resources
.PHONY: plan-target
plan-target: check-env
ifndef TARGET
	@echo "$(RED)Error: TARGET not specified$(NC)"
	@echo "Usage: make plan-target ENV=$(ENV) TARGET=<resource>"
	@exit 1
endif
	@echo "$(GREEN)Planning target $(TARGET) in $(ENV)...$(NC)"
	$(call docker_run,plan -var-file=environments/$(ENV).tfvars -target=$(TARGET) -out=tfplan-$(ENV)-target.out)

# Apply targeted changes
.PHONY: apply-target
apply-target: check-env
	@echo "$(YELLOW)Applying targeted changes for $(ENV)...$(NC)"
	@if [ ! -f "$(TERRAFORM_DIR)/tfplan-$(ENV)-target.out" ]; then \
		echo "$(RED)Error: No target plan file found. Run 'make plan-target' first$(NC)"; \
		exit 1; \
	fi
	$(call docker_run,apply tfplan-$(ENV)-target.out)

# Generate SSH key for bastion
.PHONY: generate-ssh-key
generate-ssh-key:
	@echo "$(GREEN)Generating SSH key for bastion access...$(NC)"
	@mkdir -p keys
	@ssh-keygen -t rsa -b 4096 -f keys/bastion-$(ENV) -N "" -C "bastion-$(ENV)@thoth.tfs.services"
	@echo "$(GREEN)SSH key generated at keys/bastion-$(ENV)$(NC)"
	@echo "$(YELLOW)Add this public key to your terraform variables:$(NC)"
	@cat keys/bastion-$(ENV).pub

# SSH to bastion
.PHONY: ssh-bastion
ssh-bastion: check-env
	@echo "$(GREEN)Connecting to bastion in $(ENV)...$(NC)"
	@BASTION_IP=$$(cd $(TERRAFORM_DIR) && terraform output -raw bastion_public_ip 2>/dev/null); \
	if [ -z "$$BASTION_IP" ]; then \
		echo "$(RED)Error: Bastion IP not found. Is the bastion deployed?$(NC)"; \
		exit 1; \
	fi; \
	ssh -i keys/bastion-$(ENV) -o StrictHostKeyChecking=no ec2-user@$$BASTION_IP

# Monitor deployment
.PHONY: monitor
monitor: check-env
	@echo "$(GREEN)Monitoring deployment for $(ENV)...$(NC)"
	@./scripts/monitor-deployment.sh $(ENV)

# Deploy bastion (interactive)
.PHONY: deploy-bastion
deploy-bastion:
	@echo "$(GREEN)Starting bastion deployment...$(NC)"
	@./scripts/deploy-bastion.sh

# Clean up generated files
.PHONY: clean
clean:
	@echo "$(GREEN)Cleaning up temporary files...$(NC)"
	@rm -rf $(TERRAFORM_DIR)/.terraform
	@rm -f $(TERRAFORM_DIR)/.terraform.lock.hcl
	@rm -f $(TERRAFORM_DIR)/tfplan-*.out
	@rm -f $(TERRAFORM_DIR)/terraform.tfstate*
	@echo "$(GREEN)Cleanup complete$(NC)"

# Create backend configuration
.PHONY: create-backend
create-backend:
	@echo "$(GREEN)Creating S3 backend resources...$(NC)"
	@./scripts/create-backend.sh $(ENV)

# Environment shortcuts
.PHONY: plan-staging plan-production apply-staging apply-production
plan-staging:
	@$(MAKE) plan ENV=staging

plan-production:
	@$(MAKE) plan ENV=production

apply-staging:
	@$(MAKE) apply ENV=staging

apply-production:
	@$(MAKE) apply ENV=production

# Help target
.PHONY: help
help:
	@echo "$(GREEN)TFS AI Infrastructure Makefile$(NC)"
	@echo ""
	@echo "Usage: make <target> ENV=<environment>"
	@echo ""
	@echo "$(YELLOW)Local Development:$(NC)"
	@echo "  local                - Start backend only (pre-built assets)"
	@echo "  local-dev            - Start with Vite hot-reload for UI dev"
	@echo "  local-stop           - Stop all local services"
	@echo "  local-logs           - View Laravel logs"
	@echo "  local-logs-vite      - View Vite dev server logs"
	@echo "  local-build          - Build frontend assets"
	@echo "  local-shell          - Open shell in Laravel container"
	@echo "  local-npm            - Run npm command (CMD=\"...\")"
	@echo ""
	@echo "$(YELLOW)Testing:$(NC)"
	@echo "  test                 - Run all tests locally"
	@echo "  test-unit            - Run unit tests only"
	@echo "  test-feature         - Run feature tests only"
	@echo "  test-articles        - Run article-related tests only"
	@echo "  test-coverage        - Run tests with coverage report"
	@echo "  test-filter          - Run specific test (FILTER=testname)"
	@echo "  test-fresh           - Run tests with fresh database"
	@echo ""
	@echo "$(YELLOW)Environments:$(NC)"
	@echo "  dev, staging, production"
	@echo ""
	@echo "$(YELLOW)Terraform targets:$(NC)"
	@echo "  init                 - Initialize Terraform"
	@echo "  plan                 - Plan infrastructure changes"
	@echo "  apply                - Apply infrastructure changes"
	@echo "  destroy              - Destroy infrastructure (requires confirmation)"
	@echo "  show                 - Show current state"
	@echo "  output               - Show output values"
	@echo ""
	@echo "$(YELLOW)Targeted operations:$(NC)"
	@echo "  plan-target          - Plan specific resource (TARGET=resource)"
	@echo "  apply-target         - Apply specific resource changes"
	@echo ""
	@echo "$(YELLOW)Utilities:$(NC)"
	@echo "  fmt                  - Format Terraform files"
	@echo "  validate             - Validate configuration"
	@echo "  clean                - Clean temporary files"
	@echo "  generate-ssh-key     - Generate SSH key for bastion"
	@echo "  ssh-bastion          - SSH to bastion host"
	@echo "  monitor              - Monitor deployment status"
	@echo "  deploy-bastion       - Interactive bastion deployment"
	@echo "  create-backend       - Create S3 backend resources"
	@echo ""
	@echo "$(YELLOW)Shortcuts:$(NC)"
	@echo "  plan-staging         - Plan staging environment"
	@echo "  plan-production      - Plan production environment"
	@echo "  apply-staging        - Apply staging environment"
	@echo "  apply-production     - Apply production environment"
	@echo ""
	@echo "$(YELLOW)Examples:$(NC)"
	@echo "  make local-dev                                    - Start with UI hot-reload"
	@echo "  make local                                        - Start backend only"
	@echo "  make test                                         - Run all tests"
	@echo "  make test-filter FILTER=test_can_list_articles    - Run specific test"
	@echo "  make local-npm CMD=\"install lodash\"               - Run npm command"
	@echo "  make plan ENV=staging"
	@echo "  make apply ENV=staging"

.PHONY: check-docker
check-docker:
	@if ! command -v docker &> /dev/null; then \
		echo "$(RED)Error: Docker is not installed$(NC)"; \
		exit 1; \
	fi