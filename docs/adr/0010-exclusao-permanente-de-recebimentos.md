# Exclusão permanente de recebimentos

## Status

Aceito

## Contexto

Recebimentos incorretos podem gerar aplicações e créditos que deixam os saldos inconsistentes. O usuário precisa poder remover esses registros explicitamente, mantendo rastreabilidade da operação e devolvendo os saldos afetados ao estado calculado sem o recebimento.

## Decisão

Permitir a exclusão permanente de um Recebimento após confirmação explícita. A operação remove suas aplicações, reconcilia os saldos da pessoa e registra os valores anteriores, as aplicações removidas e a reconciliação no histórico de auditoria.

Esta decisão não altera o comportamento da exclusão de Parcelamentos: quando um Parcelamento for excluído, os Recebimentos continuam registrados, como definido na ADR 0009.

## Consequências

- A exclusão de um Recebimento não pode ser desfeita pela aplicação.
- A confirmação deve identificar a pessoa, a data e o valor do Recebimento.
- A remoção das aplicações impede referências órfãs e permite que outros Recebimentos sejam recalculados contra os saldos abertos.
- O histórico de auditoria preserva os dados do Recebimento e identifica as aplicações removidas.
