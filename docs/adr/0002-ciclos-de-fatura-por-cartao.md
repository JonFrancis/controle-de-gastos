# Ciclos de fatura e vencimento por cartão de crédito

O aplicativo terá relatórios por mês calendário e por fatura. Cada forma de pagamento do tipo crédito terá configurações versionadas de fechamento e vencimento; compras posteriores ao fechamento entrarão na fatura seguinte, e o filtro de faturas usará o mês do vencimento. Débito, Pix, dinheiro e outros meios serão agrupados pela data da transação na visão de Movimentação. A composição da fatura continuará derivada dinamicamente, sem entidade persistida ou status de pagamento nesta versão.

## Consequências

- O lançamento mantém a data original da compra e uma referência calculada para o ciclo da fatura.
- Recorrências e parcelamentos em cartão seguem o fechamento do cartão automaticamente.
- Relatórios precisam distinguir mês calendário, Ciclo de Fatura, Fechamento da Fatura e Vencimento da Fatura.
- Alterações no fechamento ou vencimento criam uma nova configuração com vigência, preservando a classificação dos ciclos anteriores.
- A visão principal de consulta prioriza Faturas; Movimentação permanece separada para lançamentos por mês-calendário.
- O status de pagamento da fatura será tratado em uma issue futura e não faz parte desta decisão.

## Limites e aproximação histórica

Para localizar uma Fatura pelo mês do vencimento, a análise, a listagem e a materialização de ocorrências consultam o mês selecionado e os dois meses-calendário anteriores. Essa janela cobre o maior ciclo possível quando fechamento e vencimento ocorrem no dia 1; a classificação final continua sendo feita pela configuração vigente na data da Compra.

Cada lançamento de Crédito usa a configuração versionada cuja vigência cobre sua data. Configurações migradas de cartões existentes podem ter due_day nulo porque o histórico de vencimento não existia antes da versionação. Nessa situação, o sistema preserva a versão antiga, mas usa a configuração vigente como aproximação; se ela também não tiver vencimento, o lançamento permanece classificado pelo mês de fechamento. Configurações aposentadas ao trocar a Forma de pagamento para não-Crédito não participam de novas Faturas, mas permanecem armazenadas para preservar o histórico.
