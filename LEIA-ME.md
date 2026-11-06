# Pipoca Max — site de streaming

Site em PHP + MySQL, responsivo (PC e celular), tema preto e vermelho, com painel administrativo completo.

## O que precisa na hospedagem
- PHP 8.0 ou superior, com as extensões **PDO MySQL**, **mbstring** e **cURL** (quase todas as hospedagens já têm)
- Um banco **MySQL** (5.7+) ou MariaDB (10.3+)

## Instalação (5 minutos)
1. Envie **todo o conteúdo desta pasta** para a hospedagem (pelo Gerenciador de Arquivos ou FTP), dentro da pasta pública (`public_html`, `htdocs` ou `www`).
2. No navegador, abra `https://seusite.com/install/`.
3. Preencha os dados do seu banco MySQL (servidor, nome do banco, usuário e senha), o nome do site e crie sua conta de administrador.
4. Clique em **Instalar**. Pronto: as tabelas são criadas sozinhas.
5. Pronto: depois de instalado, o instalador se tranca sozinho (não precisa apagar a pasta `install`).

> Se o seu MySQL estiver em outro servidor (não "localhost"), coloque o endereço que a hospedagem do banco informou e confirme que ela permite conexões externas.

## Primeiros passos no painel (`seusite.com/admin`)
1. **Configurações › API do TMDB**: cole sua chave (crie grátis em themoviedb.org › Configurações › API) e clique em *Salvar e testar*.
2. **Importar do TMDB**: busque filmes, séries ou animes pelo nome (ou navegue pelas listas de lançamentos e populares) e importe com um clique. Séries e animes já vêm com todas as temporadas e episódios.
3. **Players**: abra um título em *Conteúdos* e adicione os players (link de embed, vídeo .mp4 ou transmissão .m3u8). Em séries/animes, use **Gerar players em massa** para criar os players de todos os episódios de uma vez.
4. **TV Online**: cadastre os canais um a um ou cole uma lista **M3U** inteira.

## Recursos
- Início estilo Netflix: destaque rotativo, Lançamentos, Em alta, Filmes, Séries, Animes e TV ao vivo
- Página do filme com escolha de players; página de série/anime com temporadas, episódios e botões Anterior/Próximo
- Busca com sugestões, filtros por gênero/ano/ordem, Minha Lista, cadastro e login
- Usuários podem reportar player quebrado (aparece em **Reportes** no painel)
- Painel: dashboard, conteúdos (ações em massa, lançamento, destaque, rascunho), importação do TMDB, temporadas/episódios, players, TV online, usuários (criar, editar, bloquear, tornar admin), reportes e configurações
- **Modo manutenção** com mensagem personalizada (você continua vendo o site normalmente)
- Opções: permitir/fechar cadastros e exigir login para assistir
- **Players automáticos**: modelos de link com `{tmdb_id}`, `{imdb_id}`, `{temporada}`, `{episodio}` que criam os players sozinhos ao importar
- Segurança: senhas criptografadas, proteção CSRF, consultas preparadas (sem SQL injection) e limite de tentativas de login

## SEO (Google)
- Links amigáveis (`/filme/123-nome-do-filme`), título e descrição por página, link canônico, Open Graph (prévia bonita no WhatsApp/Facebook) e dados estruturados (Movie, TVSeries, Breadcrumb, busca do site)
- `seusite.com/sitemap.xml` (atualiza sozinho) e `seusite.com/robots.txt` (esconde painel, login e busca do Google)
- Em **Configurações › SEO e Google**: título da página inicial, verificação do Search Console e Google Analytics
- Depois de publicar: cadastre o site no Google Search Console e envie o sitemap

## Segurança
- Ative o **SSL (HTTPS)** grátis na hospedagem e depois tire o `#` das 2 linhas de "forçar HTTPS" no arquivo `.htaccess`
- Use uma senha forte de administrador (8+ caracteres)

## Trocar dados do banco depois
Edite o arquivo `config.php` na raiz do site (ele é criado pelo instalador).
