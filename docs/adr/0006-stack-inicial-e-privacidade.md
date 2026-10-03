# Stack inicial e privacidade dos dados

O aplicativo usará Laravel com SQLite local no backend e React com TypeScript, Inertia.js, Tailwind CSS e shadcn/ui no frontend. A primeira execução será local pelo navegador, sem autenticação e para um único usuário. A integração com IA será opcional e configurada pelo usuário, enquanto o repositório público conterá apenas código, documentação e dados fictícios; a planilha real, o banco local e chaves de API ficarão fora do Git.

## Consequências

- O projeto poderá validar o domínio sem depender de hospedagem ou login.
- O frontend poderá aproveitar protótipos gerados por ferramentas React.
- Exportações e backups serão necessários para proteger os dados locais.
- Uma futura versão multiusuário ou hospedada exigirá uma decisão arquitetural posterior.
