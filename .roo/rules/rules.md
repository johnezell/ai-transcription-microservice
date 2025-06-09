always use docker container laravel-app when running npm, npx, php and related commands
when running python commands run from the docker container not the host
when creating testing or debug files prefix with ai_roo_test or ai_root_debug DO NOT prefix required files for functionality
when creating documentation always prefix with ai_doc
always remove testing and debug files after you are done with tests based on the prefix of ai_roo
you are on a windows terminal and should opt for Powershell commands
instead of curl on the host use Invoke-WebRequest
when testing websites use MCP Playwright for end-to-end testing