# Guia rápido: usando o Bubblewrap Sandbox

Este pacote coloca comandos externos em uma “caixa de areia” (sandbox) usando o Bubblewrap (bwrap). Isso ajuda a impedir que eles mexam no seu servidor ou container além do que você autorizar.

## Pré‑requisitos

- Somente Linux: o Bubblewrap é específico para Linux (funciona em containers e hosts Linux).
- O binário `bwrap` precisa estar instalado e executável. Exemplos:
  - Debian/Ubuntu: `apt-get install bubblewrap`
  - Alpine: `apk add bubblewrap`
  - Fedora/CentOS/RHEL: `yum install bubblewrap` ou `dnf install bubblewrap`
- Laravel 5–12. O código mira PHP 5.6+, mas a suíte de testes usa classes anônimas e roda a partir do PHP 7.x; use 7.x+ em produção.

## Instalação no projeto Laravel

1. `composer require securerun/bubblewrap-sandbox`
2. Publique a configuração (opcional, para personalizar): `php artisan vendor:publish --tag=sandbox-config`
3. O provider e o alias são registrados automaticamente:
   - Provider: `SecureRun\Sandbox\BubblewrapServiceProvider`
   - Facade: `SecureRun\BubblewrapSandbox` (alias `BubblewrapSandbox`)

## Conceito rápido

- Tudo que roda dentro do sandbox enxerga um sistema de arquivos mínimo.
- Você escolhe o que fica só leitura (RO) e o que pode ser escrito (RW).
- Só os diretórios que você “montar” ficam acessíveis. O resto fica escondido.
- Caminho padrão de trabalho: `/tmp`.

## Configuração (arquivo `config/sandbox.php`)

- `binary`: caminho do bwrap (padrão `/usr/bin/bwrap`; use `bwrap` se preferir buscar no PATH).
- `base_args`: flags de isolamento padrão (geralmente não precisa mexer).
- `read_only_binds`: pastas montadas como leitura (padrão: `/usr`, `/bin`, `/lib`, `/sbin`, `/etc/resolv.conf`, `/etc/ssl` e adiciona `/lib64` se existir).
- `write_binds`: pastas montadas com escrita (padrão vazio; o `/tmp` já é um tmpfs dentro do sandbox).

Para ambientes não padrão, ajuste apenas `binary`. Para expor mais pastas, adicione nos binds.

## Como executar um comando (PHP)

### Sem Laravel (instância direta)

```php
use SecureRun\BubblewrapSandboxRunner;

$sandbox = BubblewrapSandboxRunner::fromConfig(config('sandbox'));

$process = $sandbox->run(
    ['echo', 'hello'],   // comando e argumentos em array
    [],                  // binds extras (opcional)
    null,                // diretório de trabalho (opcional)
    null,                // variáveis de ambiente (opcional)
    30                   // timeout em segundos (opcional)
);

echo $process->getOutput();
```

### Com Laravel (facade `BubblewrapSandbox`)

```php
use SecureRun\BubblewrapSandbox; // alias registrado como BubblewrapSandbox

$process = BubblewrapSandbox::run(['ls', '-la']);
$saida = $process->getOutput();
```

## Expondo arquivos/pastas para o comando

Se o comando precisa ler ou gravar fora de `/tmp`, informe binds extras:

```php
$binds = [
    ['from' => '/var/www/storage/input',  'to' => '/var/www/storage/input',  'read_only' => true],
    ['from' => '/var/www/storage/output', 'to' => '/var/www/storage/output', 'read_only' => false],
];

$process = BubblewrapSandbox::run(
    ['heif-convert', '/var/www/storage/input/photo.heic', '/var/www/storage/output/photo.png'],
    $binds,
    '/var/www/storage/input', // opcional: diretório de trabalho
    null,
    60
);
```

- `from`: caminho no host.
- `to`: caminho visto dentro do sandbox (normalmente igual ao `from` para evitar confusão; só use diferente se precisar remapear paths deliberadamente).
- `read_only`: `true` para só leitura, `false` para permitir escrita.

## Verificando falhas

- Se `bwrap` não estiver disponível, você verá `BubblewrapUnavailableException`. Instale o pacote do sistema ou ajuste `binary`.
- Se o comando falhar, `run()` lança exceção (via `mustRun`). Use `getErrorOutput()` para ver o stderr:

```php
try {
    $process = BubblewrapSandbox::run(['false']);
} catch (\Throwable $e) {
    // lidar com erro
}
```

## Boas práticas

- Conceda apenas o mínimo de pastas necessárias nos binds.
- Prefira passar argumentos em array (sem shell) para evitar injeção.
- Defina timeouts razoáveis para evitar travar a fila/worker.
- Mantenha logs do comando e do stderr para diagnóstico.
