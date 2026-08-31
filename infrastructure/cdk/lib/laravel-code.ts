import { packagePhpCode } from '@bref.sh/constructs';
import type { Code } from 'aws-cdk-lib/aws-lambda';
import path from 'node:path';

export function packageLaravelCode(): Code {
    return packagePhpCode(path.join(__dirname, '../../..'), {
        exclude: [
            '.env',
            '.env.*',
            '.agents',
            '.claude',
            '.codex',
            '.cursor',
            '.github',
            '.idea',
            '.vscode',
            '.dockerignore',
            '.editorconfig',
            '.gitattributes',
            '.gitignore',
            '.mcp.json',
            '.npmrc',
            '.phpunit.result.cache',
            'AGENTS.md',
            'CLAUDE.md',
            'boost.json',
            'composer.json',
            'composer.lock',
            'database',
            'docker',
            'docker-compose.yml',
            'docs',
            'infrastructure',
            'node_modules',
            'public/build',
            'storage/logs/*',
            'tests',
            'Makefile',
            'README.md',
            'package.json',
            'package-lock.json',
            'phpunit.xml',
            'vite.config.js',
        ],
    });
}
