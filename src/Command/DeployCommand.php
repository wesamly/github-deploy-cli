<?php

namespace App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

class DeployCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('deploy:setup')
            ->setDescription('Set up GitHub deployment with repo-specific SSH key')
            ->addOption('repo', null, InputOption::VALUE_OPTIONAL, 'GitHub SSH repo URL (e.g., git@github.com:user/repo.git)')
            ->addOption('path', null, InputOption::VALUE_OPTIONAL, 'Target deployment directory')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Force regeneration of SSH key and config');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Step 1: Gather input
        $context = $this->collectInput($input, $io);
        if (!$context) {
            return Command::FAILURE;
        }

        // Step 2: Confirm
        if (!$this->confirmPlan($io, $context)) {
            $io->warning('Aborted by user.');
            return Command::INVALID;
        }

        // Step 3: Run setup
        return $this->runSetup($io, $context) ? Command::SUCCESS : Command::FAILURE;
    }

    private function collectInput(InputInterface $input, SymfonyStyle $io): ?array
    {
        $repoUrl = $input->getOption('repo');
        $deployPath = $input->getOption('path');
        $force = (bool) $input->getOption('force');

        if (!$repoUrl) {
            $repoUrl = $io->ask('Enter GitHub SSH repository URL (e.g., git@github.com:user/repo.git)', null, function ($answer) {
                if (!preg_match('#^git@github\.com:[^/]+/([^.]+)(\.git)?$#', $answer)) {
                    throw new \RuntimeException('Invalid GitHub SSH URL. Must be like: git@github.com:user/repo.git');
                }
                return $answer;
            });
        }

        if (!preg_match('#^git@github\.com:[^/]+/([^.]+)(\.git)?$#', $repoUrl, $matches)) {
            $io->error('Invalid GitHub SSH URL. Expected format: git@github.com:user/repo.git');
            return null;
        }
        $repoName = $matches[1];

        // Set default path to ./repoName
        $defaultDeployPath = './' . $repoName;

        if (!$deployPath) {
            $deployPath = $io->ask(
                'Enter target deployment directory (e.g., ./myapp or /var/www/app)',
                $defaultDeployPath,
                function ($answer) {
                    if (empty(trim($answer))) {
                        throw new \RuntimeException('Path cannot be empty');
                    }
                    return rtrim($answer, '/');
                }
            );
        }

        $homeDir = $_SERVER['HOME'] ?? rtrim($_SERVER['USERPROFILE'] ?? '', '/');
        if (!$homeDir) {
            $io->error('Could not determine home directory.');
            return null;
        }

        $sshDir = $homeDir . '/.ssh';
        $keyName = $repoName . '_deploy_key';
        $privateKeyPath = $sshDir . '/' . $keyName;
        $publicKeyPath = $privateKeyPath . '.pub';
        $hostAlias = "github.com-$repoName";

        return compact(
            'repoUrl',
            'deployPath',
            'force',
            'repoName',
            'homeDir',
            'sshDir',
            'keyName',
            'privateKeyPath',
            'publicKeyPath',
            'hostAlias'
        );
    }

    private function confirmPlan(SymfonyStyle $io, array $ctx): bool
    {
        $willGenerateKey = $ctx['force'] || !file_exists($ctx['privateKeyPath']);
        $willUpdateConfig = $ctx['force'] || !$this->sshConfigContainsHost("{$ctx['sshDir']}/config", $ctx['hostAlias']);
        $action = is_dir($ctx['deployPath']) && is_dir("{$ctx['deployPath']}/.git") ? 'Update (git pull)' : 'Clone';

        $io->section('Deployment Plan');
        $io->text([
            "<info>Repository:</info> {$ctx['repoUrl']}",
            "<info>Repo name:</info> {$ctx['repoName']}",
            "<info>Deploy path:</info> {$ctx['deployPath']}",
            "<info>SSH key:</info> {$ctx['privateKeyPath']} " . ($willGenerateKey ? '<fg=yellow>(will be generated)</>' : '<fg=green>(reuse existing)</>'),
            "<info>SSH host alias:</info> {$ctx['hostAlias']} " . ($willUpdateConfig ? '<fg=yellow>(will be added to config)</>' : '<fg=green>(already configured)</>'),
            "<info>Action:</info> $action",
            "<info>Force mode:</info> " . ($ctx['force'] ? 'Yes' : 'No'),
        ]);

        $io->newLine();
        return $io->confirm('Proceed with deployment setup?', false);
    }

    private function runSetup(SymfonyStyle $io, array $ctx): bool
    {
        // Prepare env
        if (!is_dir($ctx['sshDir'])) {
            if (!mkdir($ctx['sshDir'], 0700, true)) {
                $io->error("Failed to create directory: {$ctx['sshDir']}");
                return false;
            }
        }

        foreach (['git', 'ssh-keygen'] as $bin) {
            exec("which $bin", $outputLines, $exitCode);
            if ($exitCode !== 0) {
                $io->error("Required command not found: $bin");
                return false;
            }
        }

        // SSH Key
        if ($ctx['force'] || !file_exists($ctx['privateKeyPath'])) {
            if (file_exists($ctx['privateKeyPath'])) {
                unlink($ctx['privateKeyPath']);
                if (file_exists($ctx['publicKeyPath'])) {
                    unlink($ctx['publicKeyPath']);
                }
                $io->note("Force mode: Regenerating SSH key at {$ctx['privateKeyPath']}");
            } else {
                $io->note("Generating new SSH key: {$ctx['keyName']}");
            }

            $cmd = "ssh-keygen -t rsa -b 4096 -f " . escapeshellarg($ctx['privateKeyPath']) . " -N '' -q";
            exec($cmd, $outputLines, $exitCode);
            if ($exitCode !== 0) {
                $io->error("Failed to generate SSH key.");
                return false;
            }
            chmod($ctx['privateKeyPath'], 0600);
            chmod($ctx['publicKeyPath'], 0644);
            $io->success("SSH key generated: {$ctx['privateKeyPath']}");
        } else {
            $io->note("SSH key already exists. Reusing: {$ctx['privateKeyPath']}");
        }

        // SSH Config
        $configPath = "{$ctx['sshDir']}/config";
        $configBlock = <<<EOF
Host {$ctx['hostAlias']}
  HostName github.com
  User git
  IdentityFile {$ctx['privateKeyPath']}
  IdentitiesOnly yes

EOF;

        $currentConfig = file_exists($configPath) ? file_get_contents($configPath) : '';
        $blockExists = $this->sshConfigContainsHost($configPath, $ctx['hostAlias']);

        if ($ctx['force'] && $blockExists) {
            $lines = explode("\n", $currentConfig);
            $newLines = [];
            $skip = false;
            foreach ($lines as $line) {
                if (preg_match('/^Host\s+' . preg_quote($ctx['hostAlias'], '/') . '\s*$/', $line)) {
                    $skip = true;
                    continue;
                }
                if ($skip && preg_match('/^\s*Host\s+\S/', $line)) {
                    $skip = false;
                }
                if (!$skip) {
                    $newLines[] = $line;
                }
            }
            $currentConfig = implode("\n", $newLines);
        }

        if ($ctx['force'] || !$blockExists) {
            file_put_contents($configPath, rtrim($currentConfig, "\n") . "\n" . $configBlock, LOCK_EX);
            chmod($configPath, 0600);
            $io->success("SSH config updated for host alias: {$ctx['hostAlias']}");
        } else {
            $io->note("SSH config already exists for: {$ctx['hostAlias']}");
        }

        // Show key and wait for user to add it to GitHub
        $publicKey = file_get_contents($ctx['publicKeyPath']);
        $io->section('🔑 GitHub Deploy Key Required');
        $io->text([
            "Before proceeding, you must add this public key to your GitHub repository:",
            '',
            "<fg=cyan>$publicKey</>",
            '',
            "Steps:",
            "1. Go to your GitHub repo → Settings → Deploy keys",
            "2. Click 'Add deploy key'",
            "3. Paste the key above",
        ]);
        $io->newLine();

        if (!$io->confirm('Have you added this key to GitHub Deploy Keys?', false)) {
            $io->warning('Deployment paused. Add the key to GitHub, then run this command again.');
            return false;
        }

        // Git
        $customRepoUrl = "git@{$ctx['hostAlias']}:" . substr($ctx['repoUrl'], 15);
        if (is_dir($ctx['deployPath']) && is_dir("{$ctx['deployPath']}/.git")) {
            $io->note("Repository exists. Pulling latest changes...");
            chdir($ctx['deployPath']);
            exec('git pull', $outputLines, $exitCode);
            if ($exitCode !== 0) {
                $io->error("Git pull failed in {$ctx['deployPath']}");
                return false;
            }
            $io->success("Repository updated at: {$ctx['deployPath']}");
        } else {
            if (is_dir($ctx['deployPath'])) {
                $io->error("Path {$ctx['deployPath']} exists but is not a Git repo.");
                return false;
            }
            $io->note("Cloning repository to: {$ctx['deployPath']}");
            $parentDir = dirname($ctx['deployPath']);
            if (!is_dir($parentDir)) {
                mkdir($parentDir, 0755, true);
            }
            $cmd = "git clone " . escapeshellarg($customRepoUrl) . " " . escapeshellarg($ctx['deployPath']);
            exec($cmd, $outputLines, $exitCode);
            if ($exitCode !== 0) {
                $io->error("Git clone failed.");
                return false;
            }
            $io->success("Repository cloned to: {$ctx['deployPath']}");
        }

        return true;
    }

    private function sshConfigContainsHost(string $configPath, string $hostAlias): bool
    {
        if (!file_exists($configPath)) {
            return false;
        }
        $content = file_get_contents($configPath);
        return preg_match('/^Host\s+' . preg_quote($hostAlias, '/') . '\s*$/m', $content) === 1;
    }
}
