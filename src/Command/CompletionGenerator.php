<?php

declare(strict_types=1);

namespace EidCloud\ApiCli\Command;

/**
 * Generates shell auto-completion scripts for Bash and Zsh.
 */
class CompletionGenerator
{
    public static function generateBash(string $binaryName, array $commandTree): string
    {
        $subcommands = implode(' ', array_keys($commandTree));
        $date = date('Y-m-d');

        $actionsPerResource = '';
        foreach ($commandTree as $res => $actions) {
            $acts = implode(' ', array_keys($actions));
            $actionsPerResource .= "            {$res})\n                opts=\"{$acts}\"\n                ;;\n";
        }

        return <<<BASH
#!/usr/bin/env bash
# eidcloud-api-cli Bash Completion script
# Generated: {$date}

_{$binaryName}_completion() {
    local cur prev words cword
    _init_completion || return

    local commands="call generate auth completion help --help --version {$subcommands}"

    if [ \$cword -eq 1 ]; then
        COMPREPLY=( $(compgen -W "\${commands}" -- "\$cur") )
        return 0
    fi

    local sub=\${words[1]}

    case "\$sub" in
{$actionsPerResource}
        auth)
            opts="set get list remove"
            ;;
        *)
            opts="--table --json --csv --page --limit --auto-paginate --profile --header --help"
            ;;
    esac

    COMPREPLY=( $(compgen -W "\${opts}" -- "\$cur") )
    return 0
}

complete -F _{$binaryName}_completion {$binaryName}
BASH;
    }

    public static function generateZsh(string $binaryName, array $commandTree): string
    {
        $date = date('Y-m-d');
        $cases = '';
        foreach ($commandTree as $res => $actions) {
            $acts = implode(' ', array_keys($actions));
            $cases .= "        ({$res})\n            _values 'actions' {$acts}\n            ;;\n";
        }

        return <<<ZSH
#compdef {$binaryName}
# eidcloud-api-cli Zsh Completion script
# Generated: {$date}

_{$binaryName}() {
    local -a commands
    commands=(
        'call:Invoke raw API endpoint'
        'generate:Generate standalone CLI binary from OpenAPI spec'
        'auth:Manage credentials profiles'
        'completion:Generate shell autocompletion script'
        'help:Display help information'
    )

    if (( CURRENT == 2 )); then
        _describe 'command' commands
        return
    fi

    case \$words[2] in
{$cases}
        auth)
            _values 'auth commands' 'set' 'get' 'list' 'remove'
            ;;
        *)
            _arguments \\
                '--table[Render tabular output]' \\
                '--json[Render raw JSON output]' \\
                '--csv[Render CSV formatted output]' \\
                '--page=[Page number]:page:' \\
                '--limit=[Page item limit]:limit:' \\
                '--auto-paginate[Fetch all pages]' \\
                '--profile=[Auth profile]:profile:'
            ;;
    esac
}

_{$binaryName} "\$@"
ZSH;
    }
}
