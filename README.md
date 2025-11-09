# GitHub Deploy CLI

A command-line tool to deploy GitHub repositories using repository-specific SSH deploy keys. This tool automates the setup of isolated SSH keys and configuration, ensuring secure and conflict-free deployments.

Features:
- Generates an SSH key named after the repository (e.g., `myproject_deploy_key`)
- Configures `~/.ssh/config` with a unique host alias for the repository
- Guides the user through adding the public key to GitHub
- Clones the repository or updates it if already present
- Idempotent: safe to run multiple times

Ideal for server environments, or manual deployments.

---

## Installation

Download the latest release using either `wget` or `curl`:

### Using `wget`
```bash
wget https://github.com/wesamly/github-deploy-cli/releases/latest/download/deployer.phar
chmod +x deployer.phar
```

### Using `curl`
```bash
curl -L -o deployer.phar https://github.com/wesamly/github-deploy-cli/releases/latest/download/deployer.phar
chmod +x deployer.phar
```

**Requirements**:  
The target system must have PHP CLI, Git, and `ssh-keygen` installed.

---

## Quick Start

Run the setup command with your repository:

```bash
php deployer.phar deploy:setup --repo=git@github.com:username/myproject.git
```

The tool will:
1. Prompt for any missing parameters (repository URL, deployment path)
2. Generate or reuse an SSH key at `~/.ssh/myproject_deploy_key`
3. Display the public key and instructions to add it to GitHub
4. Wait for confirmation that the key has been added
5. Clone the repository to `./myproject` (or the specified path)

Once confirmed, your code is deployed and ready.

---

## Usage

### Basic Command
```bash
php deployer.phar deploy:setup \
  --repo=git@github.com:username/myproject.git \
  --path=/var/www/myproject
```

### Options
| Option | Description |
|--------|-------------|
| `--repo` | The GitHub SSH URL of the repository (e.g., `git@github.com:username/myproject.git`) |
| `--path` | Target directory for deployment. If omitted, defaults to `./<repository-name>` |
| `--force` | Regenerate the SSH key and overwrite the SSH configuration block |

The command is idempotent—running it multiple times will safely update the repository without side effects.

---

## How It Works

For a repository URL `git@github.com:username/myproject.git`:
- **SSH private key**: `~/.ssh/myproject_deploy_key`
- **SSH public key**: `~/.ssh/myproject_deploy_key.pub`
- **SSH config entry**:
  ```
  Host github.com-myproject
    HostName github.com
    User git
    IdentityFile ~/.ssh/myproject_deploy_key
    IdentitiesOnly yes
  ```
- **Clone URL used internally**: `git@github.com-myproject:username/myproject.git`

This approach ensures each repository uses its own dedicated key, avoiding permission conflicts and improving security.

---

## Development

### Run from Source
```bash
git clone https://github.com/wesamly/github-deploy-cli.git
cd github-deploy-cli
composer install
php deploy.php deploy:setup --repo=git@github.com:username/myproject.git
```

### Build the PHAR (for maintainers)
Ensure `box` is installed (e.g., via `brew install box` or your system package manager), then run:
```bash
composer install --no-dev
box compile
```

Note: The compiled `deployer.phar` is not included in the source repository. It is generated and attached to GitHub Releases.

---

## License

MIT License. See [LICENSE](LICENSE) for details.

---

## Notes

- GitHub deploy keys are read-only by default. Enable **"Allow write access"** when adding the key if your deployment process requires pushing changes (e.g., committing build artifacts).
- The tool must be run as the system user that owns the deployment directory and SSH configuration (e.g., `www-data`, `deploy`, or `root`).
- To update an existing deployment, simply re-run the same command—it will perform a `git pull`.

--- 

This tool simplifies secure, per-repository deployments with minimal setup and no external dependencies beyond standard Unix utilities.