# Exemplos: Acessando variáveis de ambiente

Este documento mostra exemplos práticos de como usar o parâmetro `unsecure_env_access` para retornar as variáveis de ambiente passadas ao método `run()`.

## Aviso de Segurança

O método `run()` **sempre retorna `ProcessWrapper`** (compatível com `Process`) para manter consistência. Por padrão, o acesso às variáveis de ambiente via `getEnv()` está desabilitado e lança exceção por questões de segurança. Use `unsecure_env_access => true` apenas quando realmente necessário e esteja ciente dos riscos de expor essas informações.

## Exemplo 1: Usando a constante `RunOptions` (Recomendado)

```php
use SecureRun\BubblewrapSandbox;
use SecureRun\RunOptions;

// Defina as variáveis de ambiente que deseja passar
$env = [
    'PYTHONPATH' => '/tmp/python-libs',
    'HOME' => '/tmp',
    'LANG' => 'pt_BR.UTF-8'
];

// Execute o comando com acesso ao env habilitado
$wrapper = BubblewrapSandbox::run(
    ['python3', 'script.py'],  // comando
    [],                         // binds extras
    null,                       // working directory
    $env,                       // variáveis de ambiente
    120,                        // timeout
    [RunOptions::UNSECURE_ENV_ACCESS => true]  // ⭐ opção para retornar env
);

// Agora você pode acessar o env
$retrievedEnv = $wrapper->getEnv();
print_r($retrievedEnv);
// Output: Array
// (
//     [PYTHONPATH] => /tmp/python-libs
//     [HOME] => /tmp
//     [LANG] => 'pt_BR.UTF-8'
// )

// O wrapper também funciona como Process normal
echo $wrapper->getOutput();
echo $wrapper->getErrorOutput();
```

## Exemplo 2: Usando string diretamente

```php
use SecureRun\BubblewrapSandbox;

$env = ['MY_VAR' => 'my_value'];

$wrapper = BubblewrapSandbox::run(
    ['echo', 'test'],
    [],
    null,
    $env,
    60,
    ['unsecure_env_access' => true]  // ⭐ usando string diretamente
);

$retrievedEnv = $wrapper->getEnv();
echo $retrievedEnv['MY_VAR']; // "my_value"
```

## Exemplo 3: Sem a opção (Padrão - env NÃO é retornado)

```php
use SecureRun\BubblewrapSandbox;

$env = ['SECRET' => 'value'];

// Sem passar a opção unsecure_env_access
$process = BubblewrapSandbox::run(
    ['echo', 'test'],
    [],
    null,
    $env,
    60
    // Sem o parâmetro $options - retorna Process normal
);

// $process é Process, não ProcessWrapper
// Não tem método getEnv() - isso está correto por segurança!
// $env não é exposto
```

## Exemplo 4: Uso completo com instância direta

```php
use SecureRun\BubblewrapSandboxRunner;
use SecureRun\RunOptions;

$config = require __DIR__ . '/config/sandbox.php';
$sandbox = BubblewrapSandboxRunner::fromConfig($config);

$env = [
    'DATABASE_URL' => 'postgresql://user:pass@localhost/db',
    'API_KEY' => 'secret-key'
];

$wrapper = $sandbox->run(
    ['my-script.sh'],
    [],
    '/tmp',
    $env,
    300,
    [RunOptions::UNSECURE_ENV_ACCESS => true]
);

// Acessar o env
$envVars = $wrapper->getEnv();
if (isset($envVars['DATABASE_URL'])) {
    echo "Database URL was: " . $envVars['DATABASE_URL'];
}

// Verificar se o comando foi bem-sucedido
if ($wrapper->isSuccessful()) {
    echo "Script executado com sucesso!";
}
```

## Exemplo 5: Processando output e recuperando env

```php
use SecureRun\BubblewrapSandbox;
use SecureRun\RunOptions;

$env = [
    'INPUT_FILE' => '/tmp/input.txt',
    'OUTPUT_DIR' => '/tmp/output',
    'LOG_LEVEL' => 'debug'
];

$wrapper = BubblewrapSandbox::run(
    ['python3', 'process-file.py'],
    [
        ['from' => '/tmp/input.txt', 'to' => '/tmp/input.txt', 'read_only' => true],
        ['from' => '/tmp/output', 'to' => '/tmp/output', 'read_only' => false]
    ],
    '/tmp',
    $env,
    180,
    [RunOptions::UNSECURE_ENV_ACCESS => true]
);

// Processar o output
$output = $wrapper->getOutput();
$errors = $wrapper->getErrorOutput();
$exitCode = $wrapper->getExitCode();

// Recuperar as variáveis de ambiente usadas
$usedEnv = $wrapper->getEnv();
echo "Processamento usado as seguintes variáveis:\n";
foreach ($usedEnv as $key => $value) {
    echo "  $key = $value\n";
}
```

## Exemplo 6: Validando env antes de usar

```php
use SecureRun\BubblewrapSandbox;
use SecureRun\RunOptions;

$env = [
    'API_ENDPOINT' => 'https://api.example.com',
    'API_TOKEN' => 'secret-token-123'
];

$wrapper = BubblewrapSandbox::run(
    ['curl', '--header', 'Authorization: Bearer $API_TOKEN', '$API_ENDPOINT/data'],
    [],
    null,
    $env,
    60,
    [RunOptions::UNSECURE_ENV_ACCESS => true]
);

// Recuperar e validar o env
$retrievedEnv = $wrapper->getEnv();

// Verificar se as variáveis necessárias estavam presentes
$requiredVars = ['API_ENDPOINT', 'API_TOKEN'];
foreach ($requiredVars as $var) {
    if (!isset($retrievedEnv[$var])) {
        throw new \RuntimeException("Variável de ambiente obrigatória não encontrada: $var");
    }
}

echo "Todas as variáveis necessárias foram passadas corretamente.\n";
```

## Resumo Rápido

### ✅ COM acesso ao env

```php
use SecureRun\BubblewrapSandbox;
use SecureRun\RunOptions;

$env = ['VAR1' => 'value1', 'VAR2' => 'value2'];

$wrapper = BubblewrapSandbox::run(
    ['my-command'],
    [],
    null,
    $env,
    60,
    [RunOptions::UNSECURE_ENV_ACCESS => true]  // habilita acesso
);

$retrievedEnv = $wrapper->getEnv(); // funciona!
print_r($retrievedEnv);
```

### SEM acesso ao env (Padrão - Seguro)

```php
use SecureRun\BubblewrapSandbox;

$env = ['VAR1' => 'value1'];

// run() sempre retorna ProcessWrapper (compatível com Process)
$wrapper = BubblewrapSandbox::run(
    ['my-command'],
    [],
    null,
    $env,
    60
    // Sem $options - comportamento padrão seguro
);

// $wrapper é ProcessWrapper, mas getEnv() não está habilitado
// $wrapper->getOutput() funciona normalmente
try {
    $wrapper->getEnv(); // lança RuntimeException
} catch (\RuntimeException $e) {
    // Comportamento esperado e seguro!
}
```

## Pontos Importantes

1. **Parâmetro `$options`**: É o 6º e último parâmetro do método `run()`
2. **Valor deve ser boolean `true`**: Não aceita strings como `'true'` ou números como `1`
3. **Retorno**: Quando habilitado, retorna `ProcessWrapper` ao invés de `Process`
4. **Segurança**: Por padrão, o env nunca é retornado (comportamento seguro)
5. **Uso da constante**: Prefira `RunOptions::UNSECURE_ENV_ACCESS` para evitar erros de digitação

## Valores Aceitos

```php
// ✅ Correto - boolean true
[RunOptions::UNSECURE_ENV_ACCESS => true]

// ✅ Correto - string literal
['unsecure_env_access' => true]

// ❌ ERRADO - não aceita string 'true'
['unsecure_env_access' => 'true']  // lançará exceção

// ❌ ERRADO - não aceita número 1
['unsecure_env_access' => 1]  // lançará exceção

// ❌ ERRADO - chave incorreta
['unsecure_env_acces' => true]  // lançará exceção (typo)
```

## Ver também

- [Parâmetros do método run()](PARAMETROS_RUN.md) - Documentação completa dos parâmetros
- [Guia de uso](USING_SANDBOX.md) - Guia geral de uso do sandbox

