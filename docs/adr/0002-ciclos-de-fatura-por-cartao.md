# Ciclos de fatura por cartão de crédito

O aplicativo terá relatórios por mês calendário e por fatura. Cada forma de pagamento do tipo crédito terá seu próprio dia de fechamento; compras posteriores ao fechamento entrarão na fatura seguinte. Débito, Pix, dinheiro e outros meios serão agrupados pela data da transação. O cadastro não terá vencimento de fatura nesta primeira versão, pois a necessidade definida é controlar o fechamento e a composição das faturas.

## Consequências

- O lançamento mantém a data original da compra e uma referência calculada para o ciclo da fatura.
- Recorrências e parcelamentos em cartão seguem o fechamento do cartão automaticamente.
- Relatórios precisam distinguir mês calendário de período de fatura.
