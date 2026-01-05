# Parâmetros do método `run()`

O método `run()` executa um comando dentro do sandbox e lança exceção se falhar.

## Assinatura

```php
public function run(
    array $command,
    array $extraBinds = [],
    string|null $workingDirectory = null,
    array|null $env = null,
    int|null $timeout = 60
): \Symfony\Component\Process\Process
```

## Parâmetros

### 1. `$command` (obrigatório)
**Tipo:** `array<int, string>`

Comando e argumentos a serem executados dentro do sandbox, em formato de array.

**Exemplo:**
```php
['echo', 'hello', 'world']
['php', 'script.php', '--arg=value']
['ls', '-la', '/tmp']
```

### 2. `$extraBinds` (opcional)
**Tipo:** `array<int, mixed>`  
**Padrão:** `[]`

Montagens adicionais de diretórios/arquivos para o sandbox. Cada item deve ser um array com:
- `from`: caminho no host (origem)
- `to`: caminho dentro do sandbox (destino)
- `read_only`: `true` para somente leitura, `false` para permitir escrita

**Exemplo:**
```php
[
    ['from' => '/var/www/input', 'to' => '/var/www/input', 'read_only' => true],
    ['from' => '/var/www/output', 'to' => '/var/www/output', 'read_only' => false],
]
```

### 3. `$workingDirectory` (opcional)
**Tipo:** `string|null`  
**Padrão:** `null`

Diretório de trabalho dentro do sandbox onde o comando será executado. Se `null`, usa o diretório padrão do sandbox.

**Exemplo:**
```php
'/tmp'
'/var/www/storage'
null  // usa o padrão
```

### 4. `$env` (opcional)
**Tipo:** `array|null`  
**Padrão:** `null`

Variáveis de ambiente adicionais para o processo sandboxed. Se `null`, usa apenas as variáveis de ambiente do processo pai.

**Exemplo:**
```php
['PATH' => '/usr/bin:/bin', 'CUSTOM_VAR' => 'value']
null  // herda do processo pai
```

### 5. `$timeout` (opcional)
**Tipo:** `int|null`  
**Padrão:** `60`

Timeout em segundos antes de abortar a execução. Se `null`, não há timeout.

**Exemplo:**
```php
30   // 30 segundos
60   // 1 minuto (padrão)
120  // 2 minutos
null // sem timeout
```

## Exemplos de uso

### Exemplo básico
```php
$process = $sandbox->run(['echo', 'hello']);
echo $process->getOutput();
```

### Com binds extras
```php
$binds = [
    ['from' => '/var/www/input', 'to' => '/var/www/input', 'read_only' => true],
];

$process = $sandbox->run(
    ['php', 'script.php'],
    $binds
);
```

### Com todos os parâmetros
```php
$process = $sandbox->run(
    ['php', 'process.php', '--input=file.txt'],
    [
        ['from' => '/var/www/storage', 'to' => '/var/www/storage', 'read_only' => false],
    ],
    '/var/www/storage',  // working directory
    ['APP_ENV' => 'production'],  // env vars
    120  // timeout de 2 minutos
);
```

## Retorno

Retorna uma instância de `\Symfony\Component\Process\Process` após a execução bem-sucedida. Se o comando falhar, uma exceção é lançada.

**Métodos úteis do Process:**
- `getOutput()`: retorna a saída padrão (stdout)
- `getErrorOutput()`: retorna a saída de erro (stderr)
- `getExitCode()`: retorna o código de saída
- `isSuccessful()`: verifica se foi bem-sucedido

