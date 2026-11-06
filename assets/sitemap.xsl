<?xml version="1.0" encoding="UTF-8"?>
<xsl:stylesheet version="1.0" xmlns:xsl="http://www.w3.org/1999/XSL/Transform" xmlns:s="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">
<xsl:output method="html" encoding="UTF-8" indent="yes"/>
<xsl:template match="/">
<html lang="pt-BR">
<head>
<meta charset="utf-8"/><meta name="viewport" content="width=device-width, initial-scale=1"/>
<meta name="robots" content="noindex"/>
<title>Mapa do site</title>
<style>
body{margin:0;background:#0b0b0b;color:#eee;font:14px/1.5 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;padding:28px 16px}
.w{max-width:980px;margin:0 auto}h1{margin:0 0 6px;font-size:26px}h1 b{color:#e50914}
.ok{display:inline-block;background:rgba(46,160,67,.15);border:1px solid rgba(46,160,67,.45);color:#7ee28f;padding:6px 12px;border-radius:20px;font-weight:600;margin:10px 0 18px}
p{color:#aaa}table{width:100%;border-collapse:collapse;background:#151515;border:1px solid #262626;border-radius:10px;overflow:hidden}
th{text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.6px;color:#888;padding:10px 12px;background:#121212;border-bottom:1px solid #262626}
td{padding:8px 12px;border-bottom:1px solid #1f1f1f;word-break:break-all}a{color:#ff8a90;text-decoration:none}a:hover{text-decoration:underline}td.d{color:#888;white-space:nowrap;word-break:normal}
</style>
</head>
<body><div class="w">
<h1>Mapa do site <b>para o Google</b></h1>
<div class="ok">✓ Sitemap funcionando</div>
<xsl:choose>
<xsl:when test="s:sitemapindex">
<p>Este é o índice: o site tem muitos títulos, então o mapa foi dividido em <xsl:value-of select="count(s:sitemapindex/s:sitemap)"/> partes.</p>
<table><tr><th>Parte</th></tr>
<xsl:for-each select="s:sitemapindex/s:sitemap"><tr><td><a href="{s:loc}"><xsl:value-of select="s:loc"/></a></td></tr></xsl:for-each>
</table>
</xsl:when>
<xsl:otherwise>
<p><xsl:value-of select="count(s:urlset/s:url)"/> endereços enviados ao Google. Esta página é só para conferência: o Google lê o arquivo direto.</p>
<table><tr><th>Endereço</th><th>Atualizado</th></tr>
<xsl:for-each select="s:urlset/s:url"><tr><td><a href="{s:loc}"><xsl:value-of select="s:loc"/></a></td><td class="d"><xsl:value-of select="s:lastmod"/></td></tr></xsl:for-each>
</table>
</xsl:otherwise>
</xsl:choose>
</div></body></html>
</xsl:template>
</xsl:stylesheet>
