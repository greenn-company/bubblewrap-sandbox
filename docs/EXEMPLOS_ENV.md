# Exemplos: Acessando variáveis de ambiente

Este documento mostra exemplos práticos de como usar o parâmetro `unsecure_env_access` para retornar as variáveis de ambiente passadas ao método `run()`.

## Aviso de Segurança

O método `run()` **sempre retorna `ProcessWrapper`** (compatível com `Process`) para manter consistência. Por padrão, o acesso às variáveis de ambiente via `getEnv()` está desabilitado e lança exceção por questões de segurança.

**`unsecure_env_access => true` expõe variáveis de ambiente que podem conter:**
- Credenciais de banco de dados, APIs e serviços externos
- Tokens de autenticação e secrets
- Paths de configuração que revelam estrutura do sistema

**Riscos ao habilitar:**
- Vazamento em logs se o ProcessWrapper for registrado
- Exposição em stack traces de exceções não tratadas
- Exfiltração se o objeto for serializado ou passado para código não confiável
- Violação de princípios de menor privilégio

**Use apenas para debugging em ambiente de desenvolvimento com valores fictícios. Nunca habilite em produção.**

## Exemplo 1: Uso básico com acesso ao env

> **Aviso**: Demonstração apenas. Nunca use `UNSECURE_ENV_ACCESS` em produção.

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
//     [LANG] => pt_BR.UTF-8
// )

// O wrapper também funciona como Process normal
echo $wrapper->getOutput();
echo $wrapper->getErrorOutput();
```

## Exemplo 2: Comportamento padrão seguro (sem acesso ao env)

```php
use SecureRun\BubblewrapSandbox;

$env = ['SECRET' => 'value'];

// Sem passar a opção unsecure_env_access
$wrapper = BubblewrapSandbox::run(
    ['echo', 'test'],
    [],
    null,
    $env,
    60
    // Sem o parâmetro $options - comportamento padrão seguro
);

// $wrapper é ProcessWrapper, mas getEnv() está bloqueado por segurança
// $wrapper->getOutput() funciona normalmente
try {
    $wrapper->getEnv(); // lança RuntimeException
} catch (\RuntimeException $e) {
    // Comportamento esperado e seguro!
    // Mensagem: "Environment variable access is not enabled for this ProcessWrapper instance. To enable it, pass unsecure_env_access => true in the options parameter when calling run()."
}
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

### ❌ SEM acesso ao env (Padrão - Seguro)

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
    // Mensagem: "Environment variable access is not enabled for this ProcessWrapper instance. To enable it, pass unsecure_env_access => true in the options parameter when calling run()."
}
```

## Pontos Importantes

1. **Parâmetro `$options`**: É o 6º e último parâmetro do método `run()`
2. **Valor deve ser boolean `true`**: Não aceita strings como `'true'` ou números como `1`
3. **Retorno**: Sempre retorna `ProcessWrapper` (compatível com `Process`); a opção apenas habilita `getEnv()`
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
