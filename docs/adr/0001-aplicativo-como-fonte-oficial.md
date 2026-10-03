# Aplicativo como fonte oficial dos dados

O aplicativo será a fonte oficial para registrar e consultar os gastos. A planilha será usada apenas durante a migração e, depois, como formato de backup, exportação ou análise externa. Essa decisão reduz a duplicidade entre abas mensais e permite que o rateio detalhado seja representado como uma compra única com vários participantes.

## Consequências

- O aplicativo precisa importar os dados históricos da planilha.
- O aplicativo precisa exportar os dados para planilha, CSV e análise por IA.
- A modelagem deve preservar a compra total e seus rateios separadamente.
