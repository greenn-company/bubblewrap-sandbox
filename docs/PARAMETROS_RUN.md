# Parâmetros do método `run()`

Documentação sobre como passar parâmetros para o método `run()` do `BubblewrapSandboxRunner` ou da facade `BubblewrapSandbox`.

## Assinatura

```php
run(array $command, array $extraBinds = [], $workingDirectory = null, array $env = null, $timeout = 60, array $options = [])
```

**Retorno:** Sempre retorna `\SecureRun\ProcessWrapper` (compatível com `\Symfony\Component\Process\Process`)

## Parâmetros

### 1. `$command` (obrigatório)
**Tipo:** `array`  
**Descrição:** Comando e seus argumentos como array. O primeiro elemento é o binário/executável, os demais são argumentos.

**Exemplos:**
```php
['echo', 'hello']
['ls', '-la', '/tmp']
['gs', '-sDEVICE=pdfwrite', '-dNOPAUSE', '-sOutputFile=output.pdf', 'input.pdf']
```

### 2. `$extraBinds` (opcional)
**Tipo:** `array`  
**Padrão:** `[]`  
**Descrição:** Montagens adicionais de diretórios/arquivos no sandbox. Cada item pode ser:
- **String simples:** caminho absoluto montado como somente leitura no mesmo caminho
- **Array associativo:** `['from' => '/caminho/host', 'to' => '/caminho/sandbox', 'read_only' => true]`

**Exemplos:**
```php
// String simples (somente leitura)
['/var/www/storage/input']

// Array com controle completo
[
    ['from' => '/var/www/storage/input', 'to' => '/var/www/storage/input', 'read_only' => true],
    ['from' => '/var/www/storage/output', 'to' => '/var/www/storage/output', 'read_only' => false]
]
```

**Propriedades do array associativo:**
- `from` (string, obrigatório): Caminho no host/sistema
- `to` (string, obrigatório): Caminho visto dentro do sandbox
- `read_only` (bool, opcional, padrão: `true`): `true` para somente leitura, `false` para permitir escrita

### 3. `$workingDirectory` (opcional)
**Tipo:** `string|null`  
**Padrão:** `null`  
**Descrição:** Diretório de trabalho dentro do sandbox. Deve ser um caminho absoluto. Se `null`, usa o diretório padrão do sandbox (`/tmp`).

**Exemplos:**
```php
'/var/www/storage/input'
'/tmp'
null  // usa o padrão
```

### 4. `$env` (opcional)
**Tipo:** `array|null`  
**Padrão:** `null`  
**Descrição:** Variáveis de ambiente adicionais para o processo executado no sandbox. Array associativo onde a chave é o nome da variável e o valor é o valor.

**Exemplos:**
```php
['LANG' => 'pt_BR.UTF-8', 'HOME' => '/tmp']
null  // sem variáveis extras
```

### 5. `$timeout` (opcional)
**Tipo:** `int|null`  
**Padrão:** `60`  
**Descrição:** Timeout em segundos. Após esse tempo, o processo é finalizado. Use `null` para não ter timeout.

**Exemplos:**
```php
30   // 30 segundos
120  // 2 minutos
null // sem timeout
```

### 6. `$options` (opcional)
**Tipo:** `array`  
**Padrão:** `[]`  
**Descrição:** Opções adicionais de configuração. As opções válidas são gerenciadas pela classe `SecureRun\RunOptions`. Atualmente suporta:
- `unsecure_env_access` (bool): Se `true`, habilita o acesso às variáveis de ambiente via `getEnv()` no `ProcessWrapper` retornado. **Por padrão é `false` e `getEnv()` lança exceção por questões de segurança.**

**Opções válidas:**
Você pode usar as constantes da classe `RunOptions` para evitar erros de digitação:
```php
use SecureRun\RunOptions;

$options = [
    RunOptions::UNSECURE_ENV_ACCESS => true,
];
```

**Exemplos:**
```php
use SecureRun\RunOptions;

// Padrão: sempre retorna ProcessWrapper (funciona como Process)
$wrapper = $sandbox->run(['echo', 'hello']);
echo $wrapper->getOutput(); // funciona normalmente

// Habilitando acesso ao env usando constante (recomendado)
$wrapper = $sandbox->run(
    ['python3', 'script.py'],
    [],
    null,
    ['PYTHONPATH' => '/tmp'],
    120,
    [RunOptions::UNSECURE_ENV_ACCESS => true]
);
// $wrapper é ProcessWrapper com acesso ao env habilitado
$env = $wrapper->getEnv(); // retorna ['PYTHONPATH' => '/tmp']
echo $wrapper->getOutput(); // funciona como Process normal

// Usando string diretamente (também funciona)
$wrapper = $sandbox->run(
    ['python3', 'script.py'],
    [],
    null,
    ['PYTHONPATH' => '/tmp'],
    120,
    ['unsecure_env_access' => true]
);
// $wrapper->getEnv() agora funciona
```

## Exemplos de uso

### Exemplo básico
```php
// run() sempre retorna ProcessWrapper (compatível com Process)
$wrapper = $sandbox->run(['echo', 'hello']);
echo $wrapper->getOutput(); // "hello"
```

### Com binds e diretório de trabalho
```php
$binds = [
    ['from' => '/var/www/storage/input', 'to' => '/var/www/storage/input', 'read_only' => true],
    ['from' => '/var/www/storage/output', 'to' => '/var/www/storage/output', 'read_only' => false],
];

$wrapper = $sandbox->run(
    ['heif-convert', '/var/www/storage/input/photo.heic', '/var/www/storage/output/photo.png'],
    $binds,
    '/var/www/storage/input',  // working directory
    null,                       // env
    60                          // timeout
);
// $wrapper é ProcessWrapper (funciona como Process)
```

### Com variáveis de ambiente
```php
// ProcessWrapper sempre é retornado (compatível com Process)
$wrapper = $sandbox->run(
    ['python3', 'script.py'],
    [],
    null,
    ['PYTHONPATH' => '/tmp', 'HOME' => '/tmp'],
    120
);
// $wrapper->getOutput() funciona normalmente
```

### Com timeout personalizado
```php
$wrapper = $sandbox->run(
    ['gs', '-sDEVICE=pdfwrite', '-sOutputFile=output.pdf', 'input.pdf'],
    $binds,
    null,
    null,
    300  // 5 minutos
);
// ProcessWrapper funciona como Process normal
```

## Exemplo com retorno de variáveis de ambiente

O método `run()` **sempre retorna `ProcessWrapper`** (que é compatível com `Process`). Quando `unsecure_env_access` é `true`, o `ProcessWrapper` permite acessar as variáveis de ambiente via `getEnv()`:

```php
use SecureRun\RunOptions;

$wrapper = $sandbox->run(
    ['env'],
    [],
    null,
    ['TEST_VAR' => 'test_value', 'HOME' => '/tmp'],
    30,
    [RunOptions::UNSECURE_ENV_ACCESS => true]
);

// $wrapper é um ProcessWrapper (compatível com Process)
// Acessar o output do processo (funciona como Process normal)
echo $wrapper->getOutput();

// Acessar as variáveis de ambiente passadas
$env = $wrapper->getEnv(); // retorna ['TEST_VAR' => 'test_value', 'HOME' => '/tmp']
print_r($env);

// Tentar acessar env sem habilitar a opção lança exceção
try {
    $wrapper = $sandbox->run(['env']); // sem opção - ainda retorna ProcessWrapper
    $env = $wrapper->getEnv(); // ❌ lança RuntimeException
} catch (\RuntimeException $e) {
    // Acesso ao env não está habilitado
}
```

**⚠️ Atenção:** O método `run()` sempre retorna `ProcessWrapper` para manter consistência. O `ProcessWrapper` é compatível com `Process` e funciona normalmente para acessar output, erros, etc. A opção `unsecure_env_access` apenas habilita o acesso às variáveis de ambiente via `getEnv()`. Por padrão, `getEnv()` lança exceção por questões de segurança.

## Notas importantes

1. **Caminhos devem ser absolutos:** Todos os caminhos (`from`, `to`, `workingDirectory`) devem começar com `/`
2. **O comando é obrigatório:** O array `$command` não pode estar vazio
3. **Timeout:** Valores negativos não são permitidos
4. **Exceções:** O método lança exceção se o comando falhar (usa `mustRun()` internamente)
5. **Retorno:** O método sempre retorna `ProcessWrapper` (compatível com `Process`) para manter consistência
6. **Acesso ao env:** Por padrão, `getEnv()` lança exceção. Use `unsecure_env_access => true` apenas quando necessário e esteja ciente dos riscos de segurança

## Ver também

- [Guia de uso](USING_SANDBOX.md) - Guia geral de uso do sandbox
- [Exemplos de acesso a variáveis de ambiente](EXEMPLOS_ENV.md) - Exemplos práticos de como usar `unsecure_env_access`

