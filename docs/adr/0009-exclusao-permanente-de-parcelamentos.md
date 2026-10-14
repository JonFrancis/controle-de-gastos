# Exclusão permanente de parcelamentos

## Status

Aceito

## Contexto

Parcelamentos normalmente são encerrados por arquivamento para preservar ocorrências passadas. Ainda assim, a tela de parcelamentos precisa permitir a remoção definitiva de uma série cadastrada incorretamente ou que não deve mais existir no histórico operacional.

## Decisão

Adicionar uma ação explícita de exclusão permanente, protegida por confirmação, que remova o parcelamento completo, suas ocorrências, seus rateios e as aplicações de recebimentos que apontam para essas ocorrências ou rateios.

Os recebimentos permanecem registrados. A operação registra auditoria e reconcilia os saldos após a remoção.

## Consequências

- A exclusão não pode ser desfeita pela aplicação.
- O arquivamento continua sendo a opção adequada quando o histórico precisa ser preservado.
- Aplicações de recebimentos são removidas junto com o vínculo ao parcelamento para evitar referências órfãs e permitir que o recebimento volte a representar crédito disponível.
