# Versionamento e publicacoes

O ZapCore Gateway usa Semantic Versioning (`MAJOR.MINOR.PATCH`):

- `MAJOR`: mudanca incompativel em API, banco ou operacao.
- `MINOR`: funcionalidade nova compativel com a versao anterior.
- `PATCH`: correcao compativel, sem funcionalidade nova obrigatoria.

O arquivo `VERSION` e a fonte canonica. O script de publicacao mantem
`worker-baileys/package.json` e `worker-baileys/package-lock.json` sincronizados.

## Durante o desenvolvimento

1. Registre mudancas relevantes em `CHANGELOG.md`, dentro de `[Unreleased]`.
2. Use commits pequenos e descritivos.
3. Mantenha a branch `main` em estado publicavel.

Categorias recomendadas para o changelog: `Added`, `Changed`, `Deprecated`,
`Removed`, `Fixed` e `Security`.

## Criar uma publicacao

Execute a partir da raiz do projeto:

```powershell
.\scripts\release.ps1 -Bump patch
.\scripts\release.ps1 -Bump minor
.\scripts\release.ps1 -Bump major
```

Tambem e possivel informar uma versao exata:

```powershell
.\scripts\release.ps1 -Version 1.2.0
```

O script:

1. exige uma arvore Git limpa;
2. valida a versao e impede tags duplicadas;
3. executa lint PHP e build TypeScript;
4. atualiza `VERSION`, manifests Node e `CHANGELOG.md`;
5. cria o commit `chore(release): vX.Y.Z`;
6. cria a tag anotada `vX.Y.Z`.

Ele nao envia nada ao remoto. Revise o commit antes de publicar:

```powershell
git show --stat v1.0.1
git push origin main
git push origin v1.0.1
```

Para preparar os arquivos sem criar commit ou tag:

```powershell
.\scripts\release.ps1 -Bump patch -NoCommit
```

## Reversao

Se uma versao ainda nao foi enviada, remova apenas a tag e reverta o commit de
release pelos comandos Git apropriados. Depois de publicada, prefira criar uma
nova versao `PATCH` com a correcao, preservando o historico.
