# Instruções do projeto

- PHP 8.2+ puro, sem framework. Laravel, Symfony, Slim e similares são proibidos.
- Dependências Composer permitidas: `vlucas/phpdotenv`, `guzzlehttp/guzzle` e `phpunit/phpunit`.
- Banco de dados: MySQL 8 acessado por PDO, com prepared statements e sem ORM.
- A aplicação e seus serviços devem ser executados em Docker: PHP-FPM, Nginx, MySQL e Adminer.
- Seguir PSR-4 e PSR-12, usar `declare(strict_types=1)` e fazer injeção de dependência manual.
- Não inventar endpoints nem colunas do TSE. Se não houver confirmação, solicitar um exemplo.
- Nunca interpolar entrada do usuário ou do LLM em SQL; usar parâmetros em prepared statements.
- Manter segredos fora do controle de versão e fornecê-los por variáveis de ambiente.