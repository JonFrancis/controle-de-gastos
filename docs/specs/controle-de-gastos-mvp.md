# Especificação do MVP: Controle de Gastos

## Problem Statement

O usuário controla gastos pessoais em uma planilha com abas mensais, recorrências, parcelamentos, recebimentos e fórmulas de consolidação. A planilha permite descobrir quanto cada participante deve, quanto corresponde ao consumo próprio e como os gastos se distribuem por categoria, forma de pagamento e origem, mas exige manutenção manual, dificulta o rateio detalhado e torna análises mensais dependentes de fórmulas e prompts externos.

O usuário precisa de um aplicativo local para computador que seja a fonte oficial dos dados, preserve o histórico da planilha e gere análises, cobranças e exportações com cálculos rastreáveis.

## Solution

Construir um aplicativo local com Laravel e SQLite no backend e React, TypeScript, Inertia.js, Tailwind CSS e shadcn/ui no frontend.

O aplicativo terá uma Compra principal com valor total, Pagador, Forma de pagamento, categoria, data e Origem do gasto. Cada Compra terá um ou mais Rateios por Participante. Recorrências e Parcelamentos serão regras que geram Ocorrências por período; cada ocorrência poderá receber ajuste pontual sem alterar a regra original.

O sistema oferecerá cadastro rápido, dashboard por mês calendário ou Fatura, fechamento mensal, análise mensal, mensagens editáveis de cobrança, importação revisável da planilha, exportação para análise externa, backup local e integração opcional com OpenAI mediante ação explícita do usuário.

## User Stories

### Cadastros básicos

1. Como usuário, quero cadastrar Participantes, para atribuir Rateios e calcular cobranças.
2. Como usuário, quero editar, desativar e reorganizar categorias, sem alterar o histórico de Compras já registradas.
3. Como usuário, quero cadastrar Formas de pagamento, para identificar como cada Compra foi paga.
4. Como usuário, quero definir uma Forma de pagamento como Crédito, Débito, Pix, Dinheiro ou Outro.
5. Como usuário, quero informar o dia de Fechamento da fatura de uma Forma de pagamento do tipo Crédito.
6. Como usuário, quero ativar ou desativar uma Forma de pagamento sem apagar Compras antigas.

### Compras e rateios

7. Como usuário, quero cadastrar rapidamente uma Compra com data, descrição, valor total, Forma de pagamento, categoria e Participantes.
8. Como usuário, quero registrar quem foi o Pagador, independentemente dos Participantes do Rateio.
9. Como usuário, quero dividir uma Compra igualmente entre os Participantes.
10. Como usuário, quero informar manualmente o valor de cada Rateio.
11. Como usuário, quero informar percentuais de Rateio e calcular os valores automaticamente.
12. Como usuário, quero alterar o método de Rateio antes de salvar a Compra.
13. Como usuário, quero receber um erro quando a soma dos Rateios não for exatamente igual ao valor total da Compra.
14. Como usuário, quero ver claramente o valor total da Compra e a parcela atribuída a cada Participante.
15. Como usuário, quero registrar uma Compra em que outra pessoa pagou e eu participei do Rateio.
16. Como usuário, quero arquivar uma Compra sem apagar seu histórico.
17. Como usuário, quero restaurar uma Compra arquivada.
18. Como usuário, quero editar uma Compra e visualizar o impacto nos saldos e relatórios.

### Recorrências e parcelamentos

19. Como usuário, quero cadastrar uma Recorrência com descrição, dia, valor, Participantes, Forma de pagamento, categoria e intervalo de validade.
20. Como usuário, quero definir se uma Recorrência está ativa.
21. Como usuário, quero gerar uma Ocorrência de Recorrência para cada período aplicável.
22. Como usuário, quero editar uma Ocorrência sem alterar a regra da Recorrência.
23. Como usuário, quero cadastrar um Parcelamento com valor total, quantidade de parcelas, valor da parcela, data inicial, Participantes e Forma de pagamento.
24. Como usuário, quero visualizar a parcela atual e as parcelas futuras de um Parcelamento.
25. Como usuário, quero gerar Ocorrências de Parcelamento enquanto o período estiver ativo.
26. Como usuário, quero que Recorrências e Parcelamentos de Crédito sejam atribuídos à Fatura correta pelo dia de fechamento.
27. Como usuário, quero arquivar uma Recorrência ou Parcelamento sem apagar suas Ocorrências históricas.

### Faturas e períodos

28. Como usuário, quero consultar os dados por mês calendário.
29. Como usuário, quero consultar os dados por Fatura de cada cartão de Crédito.
30. Como usuário, quero que uma Compra posterior ao fechamento de um cartão entre na Fatura seguinte.
31. Como usuário, quero que Débito, Pix, Dinheiro e Outro sejam agrupados pela data da transação.
32. Como usuário, quero ver a data original da Compra mesmo quando ela estiver agrupada em uma Fatura.
33. Como usuário, quero alternar entre mês calendário e Fatura no dashboard.

### Saldos, recebimentos e cobranças

34. Como usuário, quero calcular o Consumo próprio a partir dos Rateios atribuídos a “Eu”.
35. Como usuário, quero calcular o Valor pago para terceiros separadamente do Consumo próprio.
36. Como usuário, quero ver quanto cada Participante deve pagar.
37. Como usuário, quero registrar um Recebimento com pessoa, data, valor e observação.
38. Como usuário, quero aplicar Recebimentos aos saldos mais antigos automaticamente.
39. Como usuário, quero ajustar manualmente a aplicação de um Recebimento.
40. Como usuário, quero que um pagamento acima do saldo gere um Crédito de participante.
41. Como usuário, quero que Créditos de participantes sejam usados em fechamentos futuros.
42. Como usuário, quero ver o Saldo líquido quando houver valores nos dois sentidos entre duas pessoas.
43. Como usuário, quero consultar o detalhamento que formou cada lado da Compensação.
44. Como usuário, quero que pessoas não identificadas não entrem automaticamente em cobranças.

### Fechamento e análise mensal

45. Como usuário, quero iniciar um Fechamento mensal para um mês calendário ou uma Fatura.
46. Como usuário, quero ver uma tabela-resumo por Participante com total bruto, abatimentos e total final a cobrar.
47. Como usuário, quero gerar uma mensagem editável de cobrança para cada pessoa.
48. Como usuário, quero copiar uma mensagem de cobrança para a área de transferência sem enviá-la automaticamente.
49. Como usuário, quero gerar um relatório dos meus próprios gastos.
50. Como usuário, quero separar Consumo próprio por categoria.
51. Como usuário, quero separar Consumo próprio por Forma de pagamento.
52. Como usuário, quero separar gastos por Origem do gasto: Manual, Recorrência e Parcelamento.
53. Como usuário, quero ver a diferença entre Consumo próprio, Valor pago para terceiros e total desembolsado.
54. Como usuário, quero ver itens sem Participante definido.
55. Como usuário, quero ver Ambiguidades, suspeitas de duplicidade, itens sem categoria e itens sem Forma de pagamento.
56. Como usuário, quero comparar os totais calculados com os totais importados da planilha quando houver referência disponível.
57. Como usuário, quero revisar e editar uma análise antes de exportá-la.

### Importação e revisão

58. Como usuário, quero importar o histórico da planilha.
59. Como usuário, quero visualizar um preview dos dados antes de salvá-los.
60. Como usuário, quero mapear as colunas da planilha para os conceitos do aplicativo.
61. Como usuário, quero preservar a origem Manual, Recorrência ou Parcelamento durante a importação.
62. Como usuário, quero identificar linhas com datas fora do período esperado.
63. Como usuário, quero detectar possíveis duplicidades sem que o sistema as remova automaticamente.
64. Como usuário, quero enviar dados incompletos para uma Fila de revisão.
65. Como usuário, quero corrigir itens da Fila de revisão e incluí-los nos cálculos somente depois da confirmação.

### Exportação, IA e backup

66. Como usuário, quero exportar o período selecionado ou o histórico completo.
67. Como usuário, quero exportar CSVs separados de Compras, Rateios, Recebimentos, Recorrências e Parcelamentos.
68. Como usuário, quero exportar um Excel consolidado.
69. Como usuário, quero exportar a Análise mensal em Markdown.
70. Como usuário, quero exportar um prompt preenchido para análise por IA.
71. Como usuário, quero manter um modelo de prompt editável e salvar versões personalizadas.
72. Como usuário, quero chamar a OpenAI somente quando solicitar explicitamente.
73. Como usuário, quero configurar a chave da API sem salvá-la no repositório.
74. Como usuário, quero usar exportação e análise sem depender da API de IA.
75. Como usuário, quero criar um backup manual completo do banco local.
76. Como usuário, quero restaurar um backup manual.
77. Como usuário, quero ativar exportação automática opcional de backup.

### Auditoria e integridade

78. Como usuário, quero que os valores sejam calculados em centavos para evitar erros de arredondamento.
79. Como usuário, quero visualizar valores em reais brasileiros.
80. Como usuário, quero usar o fuso `America/Sao_Paulo` em datas e fechamentos.
81. Como usuário, quero consultar o histórico de criação, edição, arquivamento e restauração.
82. Como usuário, quero saber por que um saldo foi calculado daquela forma.
83. Como usuário, quero corrigir dados sem perder o histórico anterior.

## Implementation Decisions

- O sistema será um aplicativo local para um único usuário na primeira versão.
- O backend será Laravel com SQLite local.
- O frontend será React com TypeScript, Inertia.js, Tailwind CSS e shadcn/ui.
- Não haverá autenticação na primeira versão.
- A aplicação será iniciada localmente com `php artisan serve`.
- O modelo de domínio separará Compra, Rateio, Participante, Pagador, Forma de pagamento, Recorrência, Parcelamento, Ocorrência, Recebimento, Fatura e Fechamento mensal.
- Compras e Rateios serão entidades separadas, para impedir duplicação em compras compartilhadas.
- Recorrências e Parcelamentos serão regras que produzem Ocorrências por período.
- Uma Ocorrência poderá receber ajuste pontual sem alterar a regra de origem.
- Formas de pagamento terão tipos Crédito, Débito, Pix, Dinheiro e Outro.
- Somente Crédito terá dia de Fechamento da fatura.
- A data da Compra será preservada, e a Fatura será calculada separadamente.
- O aplicativo terá visões por mês calendário e por Fatura.
- Saldos mostrarão valores brutos, abatimentos, Saldo líquido e créditos.
- Recebimentos serão aplicados automaticamente aos saldos mais antigos, com ajuste manual.
- Pessoas não identificadas, duplicidades suspeitas e dados incompletos irão para a Fila de revisão.
- Exclusões serão representadas por Arquivamento.
- O aplicativo armazenará valores em centavos e exibirá reais brasileiros no fuso `America/Sao_Paulo`.
- O fechamento não impedirá alterações, mas poderá ser reaberto e recalculado.
- A análise mensal será gerada sem depender de IA.
- A integração prioritária de IA será OpenAI, acionada somente pelo usuário.
- Mensagens de cobrança serão editáveis e copiadas, nunca enviadas automaticamente.
- Exportações incluirão CSVs separados, Excel consolidado, Markdown e prompt para IA.
- Backups serão manuais por padrão, com exportação automática opcional.
- O repositório público conterá somente código, documentação e dados fictícios.
- A planilha original, banco local, backups e chaves de API ficarão fora do Git.

## Testing Decisions

- O principal seam será o fluxo completo da aplicação usando Laravel, SQLite temporário e interfaces HTTP/Inertia.
- Os testes validarão comportamento externo, dados persistidos e artefatos produzidos, não detalhes internos de implementação.
- Os testes deverão cobrir cadastro de Compra e Rateio, validação de soma exata, Pagador diferente dos Participantes e Arquivamento.
- Os testes deverão cobrir Recorrências, Parcelamentos, Ocorrências ajustadas e cálculo de Fatura pelo dia de fechamento.
- Os testes deverão cobrir mês calendário, Fatura, Débito, Pix, Dinheiro e Crédito.
- Os testes deverão cobrir Saldos líquidos, Compensação, Recebimentos, Créditos de participantes e pagamentos excedentes.
- Os testes deverão cobrir importação com preview, mapeamento, Ambiguidades, duplicidades e Fila de revisão.
- Os testes deverão conferir a Análise mensal contra cálculos independentes dos mesmos dados.
- Os testes deverão validar mensagens editáveis, cópia para área de transferência e exportações CSV, Excel, Markdown e prompt.
- Os testes deverão confirmar que a chamada de IA só ocorre após ação explícita e que a aplicação continua funcionando sem API configurada.
- Os testes deverão validar backup, restauração, Arquivamento, restauração de registros e histórico de alterações.
- Os testes deverão incluir limites de mês, fechamento no último dia do mês, valores de centavos, rateios iguais e diferenças de arredondamento.
- Ainda não há código existente ou testes anteriores; a primeira suíte será criada junto com o esqueleto do aplicativo.

## Out of Scope

- Aplicativo mobile nativo.
- Sincronização em nuvem.
- Login, múltiplos usuários e permissões.
- Hospedagem pública do Laravel.
- Integração bancária ou importação automática de extratos.
- Envio automático de mensagens pelo WhatsApp.
- Vencimento de fatura nesta primeira versão.
- Recomendações financeiras, previsões e metas inteligentes.
- Exclusão permanente de Compras, Rateios, Ocorrências ou Recebimentos.
- Publicação da planilha real ou de dados pessoais no GitHub.

## Further Notes

- A planilha existente será a fonte para a migração inicial, mas não deve ser copiada para o repositório público.
- O relatório de cobrança da Irmã deverá manter a capacidade de agrupar itens por rótulos identificáveis, conforme o prompt de análise atual.
- O valor do salário mensal continuará disponível para calcular o restante depois do Consumo próprio.
- O projeto deve manter uma separação clara entre Consumo próprio, Valor pago para terceiros e total desembolsado.
- Qualquer futura versão hospedada ou multiusuário deverá revisar os ADRs de privacidade, autenticação e armazenamento.
