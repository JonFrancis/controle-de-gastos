# Saldos líquidos e créditos de participantes

O aplicativo calculará o saldo líquido entre valores a receber e valores a pagar por pessoa, mantendo o detalhamento de cada lado. Todo Recebimento será aplicado à Cobrança em aberto da própria pessoa, começando pelos lançamentos mais antigos. Recebimentos acima do saldo em aberto não serão bloqueados: formarão um crédito do participante para fechamentos futuros. Pessoas não identificadas ficarão fora das cobranças até revisão.

## Consequências

- O relatório de cobrança mostrará o valor líquido e seus componentes.
- O valor recebido reduzirá explicitamente a cobrança da pessoa, sem apagar os lançamentos originais.
- Créditos não poderão desaparecer no fechamento seguinte.
- O histórico de compras, recebimentos e compensações continuará auditável.
