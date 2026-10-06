# Ciclos de fatura e vencimento por cartão de crédito

O aplicativo terá relatórios por mês calendário e por fatura. Cada forma de pagamento do tipo crédito terá configurações versionadas de fechamento e vencimento; compras posteriores ao fechamento entrarão na fatura seguinte, e o filtro de faturas usará o mês do vencimento. Débito, Pix, dinheiro e outros meios serão agrupados pela data da transação na visão de Movimentação. A composição da fatura continuará derivada dinamicamente, sem entidade persistida ou status de pagamento nesta versão.

## Consequências

- O lançamento mantém a data original da compra e uma referência calculada para o ciclo da fatura.
- Recorrências e parcelamentos em cartão seguem o fechamento do cartão automaticamente.
- Relatórios precisam distinguir mês calendário, Ciclo de Fatura, Fechamento da Fatura e Vencimento da Fatura.
- Alterações no fechamento ou vencimento criam uma nova configuração com vigência, preservando a classificação dos ciclos anteriores.
- A visão principal de consulta prioriza Faturas; Movimentação permanece separada para lançamentos por mês-calendário.
- O status de pagamento da fatura será tratado em uma issue futura e não faz parte desta decisão.
