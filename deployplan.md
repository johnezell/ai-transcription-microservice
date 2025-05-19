# Deployment Plan: Dockerizing CDK Execution

This document outlines the plan to enhance the `scripts/deploy.sh` script by executing the AWS CDK deployment (Step 2) within a Docker container. This approach aims to standardize the execution environment, ensuring consistent versions of Node.js and the AWS CDK CLI, and improving reproducibility.

## Current State (Relevant Part of `scripts/deploy.sh`)

Currently, Step 2 of `scripts/deploy.sh` likely executes `cdk deploy` using the Node.js and CDK CLI versions available in the host environment (local machine or CI agent). This can lead to inconsistencies and version-related issues (like the Node 18 warning observed).

```bash
# ... (Excerpt from scripts/deploy.sh - Step 2)
CDK_DIR="${WORKSPACE_ROOT}/cdk-infra"
cd "${CDK_DIR}"

# Activates a local Python venv and installs requirements
# Then runs:
cdk deploy "${STACKS_TO_DEPLOY}" \
    --require-approval never \
    --profile "${AWS_PROFILE}" \
    --region "${AWS_REGION}" \
    -vvv
# ...
```

## Proposed Dockerized CDK Deployment

We will introduce a dedicated Docker environment for running CDK commands.

### 1. Create `cdk-infra/Dockerfile.cdk` (Python Project)

Since the CDK project in `cdk-infra/` is Python-based, the `Dockerfile.cdk` will be tailored to provide a Python environment, install Python dependencies, and also include Node.js for the AWS CDK CLI.

*   **Base Image:** Start from a specific Python version. Recommended: `python:3.11-slim`.
    ```dockerfile
    ARG PYTHON_VERSION=3.11-slim
    FROM python:${PYTHON_VERSION}
    ```
*   **Environment Variables & Prerequisites:** Set up for NVM, Node.js, and install necessary tools like `curl`, `bash`, `build-essential`, `git`, and importantly, the `docker-ce-cli` (or equivalent) for Docker-out-of-Docker operations.
    ```dockerfile
    ENV NVM_DIR=/usr/local/nvm
    ARG NODE_VERSION=22 # LTS Node.js version
    ARG CDK_CLI_VERSION=2.121.1 # Specify desired CDK version

    ENV PATH=${NVM_DIR}/versions/node/v${NODE_VERSION}/bin:${PATH}

    RUN apt-get update && apt-get install -y --no-install-recommends \
        curl \
        bash \
        build-essential \
        git \
        docker-ce-cli \
        && rm -rf /var/lib/apt/lists/*
    ```
*   **Install NVM, Node.js, and AWS CDK CLI:**
    ```dockerfile
    RUN mkdir -p ${NVM_DIR} && \
        curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.39.7/install.sh | bash && \
        . ${NVM_DIR}/nvm.sh && \
        nvm install ${NODE_VERSION} && \
        nvm use ${NODE_VERSION} && \
        nvm alias default ${NODE_VERSION} && \
        npm install -g aws-cdk@${CDK_CLI_VERSION} && \
        rm -rf ${NVM_DIR}/.cache
    ```
*   **Working Directory:** Set a working directory, e.g., `/usr/src/app/cdk`.
    ```dockerfile
    WORKDIR /usr/src/app/cdk
    ```
*   **Copy Python Requirements & Install Dependencies:**
    ```dockerfile
    COPY requirements.txt ./
    RUN pip install --no-cache-dir -r requirements.txt
    ```
*   **Copy Remaining CDK Code:** Copy the rest of the `cdk-infra` directory contents.
    ```dockerfile
    COPY . .
    ```
*   **Optional: Entrypoint/CMD:** Could define an entrypoint for `cdk` for convenience.
    ```dockerfile
    # CMD ["cdk", "--version"]
    ```

**Note:** The `ARG` values for `PYTHON_VERSION`, `NODE_VERSION`, and `CDK_CLI_VERSION` can be updated as needed.

### 2. Modify `scripts/deploy.sh` (Step 2)

This part of the plan remains structurally the same. The script will be updated to:

*   **Build the CDK Docker Image:** Before deploying, build an image using `cdk-infra/Dockerfile.cdk`.
    ```bash
    echo ">>> Building CDK execution Docker image..."
    # Ensure CDK_DIR is correctly defined, e.g., CDK_DIR="${WORKSPACE_ROOT}/cdk-infra"
    docker build -t cdk-deployer -f "${CDK_DIR}/Dockerfile.cdk" "${CDK_DIR}"
    ```
*   **Execute `cdk deploy` via `docker run`:** Replace the direct `cdk deploy` call. This now includes mounting the Docker socket.
    ```bash
    echo ">>> Deploying CDK stacks via Docker..."
    docker run --rm \
        -v "${HOME}/.aws:/root/.aws:ro" \      # Mount AWS credentials
        -v /var/run/docker.sock:/var/run/docker.sock \ # Mount Docker socket for DooD
        -v "${WORKSPACE_ROOT}/cdk-infra:/usr/src/app/cdk" \ # Mount CDK app source
        -v "${WORKSPACE_ROOT}/app/laravel/public:/app_assets:ro" \ # Mount Laravel assets
        -e "AWS_PROFILE=${AWS_PROFILE}" \
        -e "AWS_REGION=${AWS_REGION}" \
        -e "CDK_OUTDIR=/usr/src/app/cdk/cdk.out" \ 
        # Add any other ENV VARS your CDK app needs (e.g., ones currently sourced from .venv/bin/activate)
        cdk-deployer \
        deploy "${STACKS_TO_DEPLOY}" --require-approval never -vvv
    ```
    *   **Docker Socket Mount:** The `-v /var/run/docker.sock:/var/run/docker.sock` line is crucial for enabling Docker-out-of-Docker, allowing the CDK container to use the host's Docker daemon.
    *   **Volume Mounts & Environment Variables:** Pay close attention to environment variables that might have been set by `source .venv/bin/activate` in the old script. These might need to be explicitly passed to the `docker run` command using `-e` flags if your CDK Python app relies on them.

### Considerations:

*   **CDK Project Type (Python/TypeScript/etc.):** The `Dockerfile.cdk` will need to be tailored. The example above leans towards a Python CDK project and now includes the Docker CLI.
*   **Docker CLI Package Name:** The package name for Docker CLI (`docker-ce-cli`, `docker.io-cli`, `docker-cli`) can vary based on the Linux distribution and its repositories. Adjust as needed for the chosen Python base image.
*   **AWS Authentication:** Mounting `~/.aws` is common for local development. For CI/CD, prefer IAM roles for tasks/pods or temporary credentials via environment variables.
*   **Access to Build Artifacts:** If the CDK app needs to package the Laravel assets, the volume mount for `app/laravel/public` is crucial. The CDK code would then reference assets from the path specified in the mount (e.g., `/app_assets`).
*   **`cdk.context.json`:** This file is often generated and used by CDK. Ensure its handling is consistent. It might be read from the mounted `cdk-infra` or committed to your repository.
*   **Build Time:** An extra Docker image build step is added. This can be optimized using Docker layer caching.

## Next Steps

1.  Determine the language of the CDK project in `cdk-infra/` (Python, TypeScript, etc.).
2.  Draft the `cdk-infra/Dockerfile.cdk` based on the project language.
3.  Implement the changes in `scripts/deploy.sh` to build and run the CDK Docker container.
4.  Test thoroughly. 