# Controle de Gastos

Vocabulário do domínio de controle de gastos pessoais, rateios, cobranças e fechamento mensal.

## Lançamentos e rateios

**Compra**:
Um gasto identificado por data, descrição, valor total, pagador, forma de pagamento, categoria e origem.
_Evitar_: gasto individual quando estiver falando do valor total da compra.

**Rateio**:
A distribuição do valor de uma compra entre seus participantes.
_Evitar_: divisão, parcela da pessoa.

**Participante**:
Pessoa à qual uma parte do valor de uma compra é atribuída.
_Evitar_: beneficiário, envolvido.

**Pagador**:
Pessoa ou conta que efetivamente realizou o pagamento da compra.
_Evitar_: responsável pelo gasto.

**Origem do gasto**:
Classificação que indica se uma compra foi registrada manualmente, gerada por uma recorrência ou gerada por um parcelamento.
_Evitar_: tipo de lançamento.

**Recorrência**:
Regra que representa um gasto esperado em vários períodos, com seus dados e intervalo de validade.
_Evitar_: gasto fixo, lançamento repetido.

**Parcelamento**:
Regra que representa uma compra dividida em parcelas ao longo de um período.
_Evitar_: mensalidade, recorrência.

**Ocorrência**:
Instância de uma recorrência ou de um parcelamento que pertence a um período específico.
_Evitar_: lançamento automático quando a origem precisa ser preservada.

**Forma de pagamento**:
Cartão, Pix, dinheiro ou outro meio usado para pagar uma compra. Uma forma do tipo crédito possui configurações versionadas para o ciclo de fatura, incluindo fechamento e vencimento.
_Evitar_: conta bancária quando o registro representa um cartão.

**Ciclo de fatura**:
Período de compras de um cartão entre o dia seguinte ao fechamento anterior e o dia do fechamento atual.
_Evitar_: mês calendário, mês de vencimento.

**Fechamento da fatura**:
Dia do mês em que o cartão encerra o período de uma fatura e passa novas compras para a fatura seguinte.
_Evitar_: data de pagamento, vencimento.

**Vencimento da fatura**:
Data em que a fatura deve ser paga. É calculada como a primeira ocorrência posterior ao fechamento para o dia de vencimento configurado no cartão.
_Evitar_: fechamento da fatura, data da compra.

**Fatura**:
Conjunto de compras de um único cartão agrupadas por um ciclo de fatura e identificadas pela data de vencimento.
_Evitar_: mês do gasto, mensalidade do cartão.

## Saldos e fechamento

**Consumo próprio**:
Valor dos rateios atribuídos a “Eu”, independentemente de quem pagou.
_Evitar_: meus gastos, gasto pessoal, gasto pago.

**Gasto total do mês**:
Total do consumo próprio registrado no mês selecionado, somando os valores atribuídos a “Eu”.
_Evitar_: total desembolsado, total da fatura.

**Restante do salário**:
Valor do salário mensal configurado depois de descontado o gasto total do mês; pode ser positivo ou negativo.
_Evitar_: saldo bancário, dinheiro disponível.

**Gasto por pessoa**:
Soma dos valores atribuídos a cada participante nas compras do período selecionado.
_Evitar_: saldo por pessoa, valor a receber.

**Movimentação mensal**:
Consulta dos lançamentos pelo mês-calendário, usada especialmente para formas de pagamento que não possuem fatura, como Pix, Débito, Dinheiro e Outro.
_Evitar_: histórico de compras, saldo mensal.

**Valor pago para terceiros**:
Valor desembolsado pelo usuário em compras cujo rateio inclui outras pessoas.
_Evitar_: gasto meu, consumo próprio.

**Cobrança**:
Valor líquido que um participante deve devolver ao pagador depois de abatimentos e recebimentos.
_Evitar_: dívida, saldo bruto.

**Saldo líquido**:
Resultado da compensação entre valores que uma pessoa deve pagar e valores que o usuário deve pagar a essa mesma pessoa.
_Evitar_: total bruto, cobrança isolada.

**Crédito de participante**:
Valor recebido além dos saldos em aberto de uma pessoa, mantido para compensação em fechamentos futuros.
_Evitar_: saldo negativo, pagamento excedente sem destino.

**Compensação**:
Abatimento entre valores que duas pessoas devem uma à outra, preservando os lançamentos que formaram cada lado do saldo.
_Evitar_: exclusão de dívida, quitação automática sem histórico.

**Recebimento**:
Valor já devolvido por um participante ao pagador, associado a uma pessoa e a uma data. Ao ser registrado, reduz a Cobrança em aberto dessa pessoa, começando pelos lançamentos mais antigos; o excedente permanece como Crédito de participante.
_Evitar_: pagamento recebido sem contexto.

**Ambiguidade**:
Situação em que um lançamento importado ou registrado não possui informação suficiente para ser classificado com segurança.
_Evitar_: erro, inválido.

**Arquivamento**:
Estado de uma compra ou ocorrência que deixa de participar dos cálculos ativos sem perder seu histórico.
_Evitar_: exclusão lógica quando estiver falando com o usuário.

**Fila de revisão**:
Conjunto de lançamentos incompletos, ambíguos ou potencialmente duplicados que aguardam decisão do usuário.
_Evitar_: erros de importação.

**Fechamento mensal**:
Análise de um mês que consolida lançamentos manuais, recorrências e parcelamentos, calcula saldos e produz relatórios e mensagens.
_Evitar_: aba do mês, relatório mensal quando estiver falando do processo completo.

**Análise mensal**:
Relatório do fechamento mensal que apresenta cobranças, consumo próprio, categorias, formas de pagamento, origens, ambiguidades e conferência dos totais.
_Evitar_: resumo financeiro quando o relatório precisa incluir as conferências.
