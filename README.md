# API PHP da brapi para o prototipo Marcopolo

API pequena, sem dependencias externas, para encaminhar cotacoes e historico da brapi sem expor `BRAPI_TOKEN` no frontend.

## Requisitos

- PHP 8.0 ou superior;
- extensao cURL habilitada;
- HTTPS de saida permitido pelo servidor.

O banco de dados nao e necessario para esta API.

## Rotas

- `GET /api/market/quote?symbols=POMO3,POMO4`
- `GET /api/market/historical?symbols=POMO3,POMO4&range=1mo`

As rotas aceitam ate 10 simbolos por chamada. O historico usa intervalo diario e ordenacao ascendente. Sem token, somente os tickers de teste publicos PETR4, VALE3, MGLU3 e ITUB4 sao consultados.

## Executar localmente no Windows

Copie `.env.example` para `.env` e coloque a chave da brapi em `BRAPI_TOKEN`. O arquivo `.env` ja esta ignorado pelo Git.

No PowerShell, na pasta do projeto:

```powershell
$secureToken = Read-Host "Chave nova da brapi" -AsSecureString
$env:BRAPI_TOKEN = (New-Object System.Net.NetworkCredential("", $secureToken)).Password
$env:CORS_ALLOWED_ORIGINS = "http://localhost:5500,http://127.0.0.1:5500,http://localhost:8000,http://127.0.0.1:8000"
php -S localhost:8080 -t .\public .\public\router.php
```

O prompt nao mostra a chave. O servidor le `BRAPI_TOKEN` do ambiente no inicio do processo; feche o servidor para descartar essa variavel da sessao.
Se o PHP do XAMPP nao estiver no `PATH`, substitua a ultima linha por `& "C:\xampp\php\php.exe" -S localhost:8080 -t .\public .\public\router.php`.

Teste a rota de cotacao em outro terminal:

```powershell
curl.exe "http://localhost:8080/api/market/quote?symbols=PETR4%2CVALE3"
```

## Publicar

### Render (Docker)

O repositorio inclui um `Dockerfile` para publicar esta API como Web Service no Render. Na tela de configuracao:

- **Name:** escolha um nome, por exemplo `api-marcopolo`;
- **Language:** `Docker`;
- **Branch:** `main` (ou a branch que contem o codigo);
- **Region:** escolha a regiao mais proxima do frontend ou dos usuarios;
- **Root Directory:** deixe vazio;
- **Dockerfile Path:** `./Dockerfile`;
- **Compute:** o plano Free serve para validar; ele pode dormir quando fica sem acessos e a primeira requisicao depois disso demora mais;
- **Environment Variables:** adicione `BRAPI_TOKEN` com sua chave da brapi e `CORS_ALLOWED_ORIGINS` com a origem exata do frontend, por exemplo `https://seu-usuario.github.io` (sem barra no final). Para mais de uma origem, separe com virgula.

Nao use **Add from .env** para publicar o arquivo local: configure somente essas variaveis no painel e nao envie o `.env` ao repositorio. O Dockerfile instala PHP e cURL e inicia o servidor na porta `PORT` fornecida pelo Render. Depois do deploy, teste `https://NOME-DO-SERVICO.onrender.com/api/market/quote?symbols=PETR4`.

Configure o document root da hospedagem para a pasta `public` ou copie somente o conteudo dela para o document root. Em hospedagem Apache, o `.htaccess` encaminha as rotas da API para `index.php`. Configure `BRAPI_TOKEN` no ambiente do PHP e `CORS_ALLOWED_ORIGINS` com as origens exatas do frontend, separadas por virgulas, por exemplo `https://usuario.github.io,https://seu-dominio.com`.

Nao coloque a chave no repositorio, em `index.php`, no `.htaccess` ou no JavaScript do frontend. Se a hospedagem nao permitir variaveis de ambiente, confirme com o provedor um metodo seguro para fornecer segredos ao PHP antes de publicar.
