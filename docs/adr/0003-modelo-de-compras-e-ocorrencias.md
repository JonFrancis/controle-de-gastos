# Compras, rateios e ocorrências

O domínio será modelado com uma compra principal, seus rateios e, quando aplicável, uma recorrência ou um parcelamento que gera ocorrências por período. A importação histórica preservará a origem e marcará ambiguidades para revisão. Ocorrências poderão receber ajustes pontuais sem alterar a regra que as originou.

## Consequências

- Uma compra dividida não será duplicada para representar seus participantes.
- Recorrências e parcelamentos terão histórico e poderão ser conferidos por ocorrência.
- O aplicativo deverá diferenciar regra, ocorrência, compra e rateio nos relatórios.
