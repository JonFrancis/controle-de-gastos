# Controle de Gastos

Aplicativo desktop local para registrar compras, organizar despesas compartilhadas e acompanhar o fechamento financeiro do mês. Os dados ficam em um banco SQLite no computador; o aplicativo não oferece uma conta online nem sincronização entre dispositivos.

## O que o aplicativo faz

- Mostra um painel mensal com despesas e navegação entre períodos.
- Registra compras com pagador, participante, categoria e forma de pagamento. Uma compra pode ser rateada entre várias pessoas.
- Organiza cartões de crédito por ciclo de fatura, com histórico das datas de fechamento e vencimento. Também permite Pix, débito, dinheiro e empréstimos.
- Acompanha parcelamentos e despesas recorrentes, com ocorrências mensais que podem ser ajustadas sem alterar as demais.
- Registra recebimentos e aplica valores recebidos às partes de compras atribuídas a outras pessoas.
- Calcula saldos e apresenta a análise mensal por pessoa, categoria, forma de pagamento e origem dos lançamentos, com conferência dos totais.
- Importa planilhas CSV e Excel em etapas: mapeamento de colunas, revisão dos lançamentos e confirmação da importação.
- Exporta compras, rateios, recebimentos, recorrências, parcelamentos e análises em CSV, Excel e Markdown. Também gera um prompt para análise externa, que pode ser editado, copiado ou baixado.
- Mantém histórico de alterações e permite criar, configurar e restaurar backups locais.
- Permite cadastrar pessoas, categorias e formas de pagamento.

## Tecnologias

- PHP 8.4.27 ou superior e Laravel 13
- SQLite
- React, TypeScript, Inertia e Tailwind CSS
- NativePHP Desktop e Electron
- Composer, Node.js e pnpm

## Requisitos para desenvolvimento

- PHP **8.4.27 ou superior**, com a extensão `pdo_sqlite` habilitada
- Composer 2
- Node.js 22 ou superior; Node.js 24 é usado nas verificações automatizadas do projeto
- pnpm 11
- Git

Confira as ferramentas antes de começar:

```powershell
php -v
composer --version
node --version
pnpm --version
```

## Executar no computador

Os comandos abaixo usam PowerShell no Windows. No macOS ou Linux, os passos são os mesmos; use `cp .env.example .env` para copiar as configurações e `touch database/database.sqlite` para criar o banco vazio.

1. Clone o repositório e entre na pasta:

   ```powershell
   git clone https://github.com/JonFrancis/controle-de-gastos.git
   cd controle-de-gastos
   ```

2. Instale as dependências PHP e JavaScript:

   ```powershell
   composer install
   npm install --global pnpm@11
   pnpm install --frozen-lockfile
   ```

3. Crie a configuração local e o banco SQLite vazio:

   ```powershell
   Copy-Item .env.example .env
   New-Item -ItemType File -Path database/database.sqlite -Force | Out-Null
   ```

   Confirme no `.env` que `DB_CONNECTION=sqlite` e `DESKTOP_INCLUDE_SOURCE_DATABASE=false`. O `.env` é ignorado pelo Git.

4. Gere a chave local do Laravel e crie as tabelas:

   ```powershell
   php artisan key:generate
   php artisan migrate --force
   ```

5. Inicie o aplicativo desktop com Vite:

   ```powershell
   composer run native:dev
   ```

   O comando prepara as dependências do Electron, inicia o servidor local do NativePHP e abre a janela do aplicativo. Encerre com `Ctrl+C` no terminal.

### Dados demonstrativos

Em um banco novo, carregue registros fictícios para explorar as telas:

```powershell
php artisan db:seed --class=VisualTestSeeder
```

Essa massa cria as pessoas `Eu`, `Teste 1` e `Teste 2`, com compras em Pix, crédito, débito e dinheiro, além de rateio, recebimento, parcelamento e recorrência. As datas acompanham o mês atual para que o painel já abra com dados visíveis. As descrições demonstrativas usam o prefixo `VISUAL TEST |`.

O seeder é destinado a uma base limpa e não é idempotente. **Não o execute no banco com seus dados pessoais nem mais de uma vez na mesma base.** Para uma demonstração, use uma cópia separada do arquivo SQLite e mantenha seu banco pessoal em backup.

## Comandos úteis

```powershell
pnpm run build                 # compila os recursos de interface
composer test                  # testes PHP
pnpm run test:frontend          # testes de interface
php artisan native:build win x64 --no-interaction  # pacote desktop para Windows x64
```
