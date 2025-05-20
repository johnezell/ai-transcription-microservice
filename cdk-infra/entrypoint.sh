#!/bin/bash
set -e # Exit immediately if a command exits with a non-zero status

# Source NVM environment variables and add Node/NPM to PATH
export NVM_DIR="/usr/local/nvm"
# The following line is crucial: it loads nvm into the current shell session.
# shellcheck source=/dev/null
[ -s "${NVM_DIR}/nvm.sh" ] && . "${NVM_DIR}/nvm.sh"

# The nvm alias default should point to the correct Node version installed in Dockerfile
# nvm use default # This might be needed if nvm alias default isn't automatically picked up

echo "--- Container Runtime: Verifying Versions ---"
echo "Node version: $(node -v)"
echo "NPM version: $(npm -v)"
echo "CDK CLI version: $(cdk --version)"
echo "User: $(whoami)"
echo "PATH: $PATH"
echo "--- End Container Runtime Version Verification ---"

# Now, execute the command passed into the docker container (e.g., "cdk deploy ...")
exec "$@" 