# Guia rápido: usando o Bubblewrap Sandbox

Este pacote coloca comandos externos em uma “caixa de areia” (sandbox) usando o Bubblewrap (bwrap). Isso ajuda a impedir que eles mexam no seu servidor ou container além do que você autorizar.

## Pré‑requisitos

- Somente Linux: o Bubblewrap é específico para Linux (funciona em containers e hosts Linux).
- O binário `bwrap` precisa estar instalado e executável. Exemplos:
  - Debian/Ubuntu: `apt-get install bubblewrap`
  - Alpine: `apk add bubblewrap`
  - Fedora/CentOS/RHEL: `yum install bubblewrap` ou `dnf install bubblewrap`
- Laravel 5–12 em PHP 7.0+ (requisito do Composer). O código evita sintaxe moderna para funcionar em apps antigos, mas os testes e o suporte começam no PHP 7.x; use no mínimo PHP 7 em produção.

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

$config = require __DIR__ . '/../config/sandbox.php'; // ou um array próprio de config
$sandbox = BubblewrapSandboxRunner::fromConfig($config);

// run() sempre retorna ProcessWrapper (compatível com Process)
$wrapper = $sandbox->run(
    ['echo', 'hello'],   // comando e argumentos em array
    [],                  // binds extras (opcional)
    null,                // diretório de trabalho (opcional)
    null,                // variáveis de ambiente (opcional)
    30,                  // timeout em segundos (opcional)
    []                   // opções (opcional)
);

echo $wrapper->getOutput(); // funciona normalmente
```

### Com Laravel (facade `BubblewrapSandbox`)

```php
use SecureRun\BubblewrapSandbox; // alias registrado como BubblewrapSandbox

// run() sempre retorna ProcessWrapper (compatível com Process)
$wrapper = BubblewrapSandbox::run(['ls', '-la']);
$saida = $wrapper->getOutput();
```

### Acessando variáveis de ambiente (opcional)

O método `run()` sempre retorna `ProcessWrapper` (compatível com `Process`). Por padrão, o acesso às variáveis de ambiente via `getEnv()` está desabilitado e lança exceção por questões de segurança. Se você precisar acessá-las explicitamente, use a opção `unsecure_env_access`:

```php
use SecureRun\RunOptions;

$env = ['PYTHONPATH' => '/tmp', 'HOME' => '/tmp'];
$wrapper = BubblewrapSandbox::run(
    ['python3', 'script.py'],
    [],
    null,
    $env,
    120,
    [RunOptions::UNSECURE_ENV_ACCESS => true]
);

// $wrapper é um ProcessWrapper (compatível com Process)
$retrievedEnv = $wrapper->getEnv(); // retorna ['PYTHONPATH' => '/tmp', 'HOME' => '/tmp']
echo $wrapper->getOutput(); // funciona como Process normal
```

**⚠️ Atenção:** Use `unsecure_env_access => true` apenas quando realmente necessário. Por padrão, o método nunca retorna as variáveis de ambiente por questões de segurança. Veja [docs/PARAMETROS_RUN.md](PARAMETROS_RUN.md) para mais detalhes sobre o parâmetro `$options`.

## Expondo arquivos/pastas para o comando

Se o comando precisa ler ou gravar fora de `/tmp`, informe binds extras:

```php
$binds = [
    ['from' => '/var/www/storage/input',  'to' => '/var/www/storage/input',  'read_only' => true],
    ['from' => '/var/www/storage/output', 'to' => '/var/www/storage/output', 'read_only' => false],
];

$wrapper = BubblewrapSandbox::run(
    ['heif-convert', '/var/www/storage/input/photo.heic', '/var/www/storage/output/photo.png'],
    $binds,
    '/var/www/storage/input', // opcional: diretório de trabalho
    null,                      // opcional: variáveis de ambiente
    60                        // opcional: timeout
);
// $wrapper é ProcessWrapper (funciona como Process)
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

## Exemplos práticos

### Compactar/normalizar PDF com Ghostscript

O comando original `shell_exec('gs -sDEVICE=pdfwrite -dCompatibilityLevel=1.4 -dNOPAUSE -dQUIET -dBATCH -dPreserveAnnots=true -sOutputFile='.$finalFilePath.' '.$localFile.'');` pode ser executado dentro do sandbox assim:

```php
$binds = [
    ['from' => dirname($localFile),    'to' => dirname($localFile),    'read_only' => true],  // ler PDF de entrada
    ['from' => dirname($finalFilePath),'to' => dirname($finalFilePath),'read_only' => false], // gravar PDF final
];

$args = [
    'gs',
    '-sDEVICE=pdfwrite',
    '-dCompatibilityLevel=1.4',
    '-dNOPAUSE',
    '-dQUIET',
    '-dBATCH',
    '-dPreserveAnnots=true',
    '-sOutputFile=' . $finalFilePath,
    $localFile,
];

$wrapper = BubblewrapSandbox::run($args, $binds, null, null, 120);
// ou com opções:
// $wrapper = BubblewrapSandbox::run($args, $binds, null, null, 120, []);
// $wrapper é ProcessWrapper (funciona como Process)
```

- Use os binds para expor apenas as pastas que contêm o PDF de entrada e a pasta de saída.
- O array de argumentos evita interpolação em shell; ajuste o timeout conforme o tamanho dos arquivos.

### Converter HEIC para PNG com `heif-convert`

O método abaixo mostra como adaptar a conversão para rodar no sandbox, expondo apenas os diretórios de entrada e saída:

```php
$binds = [
    ['from' => dirname($sourcePath), 'to' => dirname($sourcePath), 'read_only' => true],
    ['from' => dirname($outputPath), 'to' => dirname($outputPath), 'read_only' => false],
];

$args = [
    // ex.: /usr/bin/heif-convert
    $heifConvertPath,
    $sourcePath,
    $outputPath,
];

$wrapper = BubblewrapSandbox::run($args, $binds, dirname($sourcePath), null, 60);
// use getErrorOutput() para stderr
$output = $wrapper->getOutput();
// $wrapper é ProcessWrapper (compatível com Process)
```

## Opções avançadas

O método `run()` aceita um parâmetro adicional `$options` para configurações avançadas. Veja a documentação completa em [docs/PARAMETROS_RUN.md](PARAMETROS_RUN.md) para detalhes sobre todas as opções disponíveis.
```

- Garanta que `heif-convert` está acessível no host; exponha apenas as pastas necessárias.
- Mantenha logs e trate `returnCode` como no exemplo original para identificar falhas.
