# Integridade monetária e auditoria

Valores serão tratados em reais brasileiros, armazenados em centavos e exibidos no formato brasileiro, usando o fuso `America/Sao_Paulo`. Compras e ocorrências serão arquivadas em vez de apagadas, alterações relevantes serão registradas, duplicidades gerarão alertas e dados incompletos permanecerão em uma fila de revisão sem entrar automaticamente nas cobranças.

## Consequências

- Totais e arredondamentos serão determinísticos.
- O histórico poderá explicar como um saldo foi formado.
- A importação será segura, mas exigirá revisão de casos ambíguos.
