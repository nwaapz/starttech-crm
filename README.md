# CRM deploy repo

Mirror of `public_html/crm/` on the host. Generated from `D:\Gate-Way-Guarantee` — do not edit files here by hand.

Server-owned and never touched by deploy: `config/`, `storage/`, `get_verif.php`.

## Update the site

```powershell
cd D:\crm-deploy
.\sync.ps1 "what changed"
```

Then in cPanel → Git Version Control → Manage → Pull or Deploy:
**Update from Remote** → **Deploy HEAD Commit**.
(If you push directly to the cPanel repo, deploy runs automatically.)

## One-time cPanel setup

### Option A — push straight to cPanel (needs SSH access)

1. cPanel → Git Version Control → Create. Turn **Clone a Repository** off.
   Repository Path: `repositories/crm-deploy` (NOT `public_html/crm`).
2. Copy the clone URL cPanel shows, then:

```powershell
git remote add origin ssh://USER@HOST:PORT/home/USER/repositories/crm-deploy
git push -u origin main
```

### Option B — via a private GitHub repo

1. Create an empty private repo on GitHub and push:

```powershell
git remote add origin https://github.com/YOU/crm-deploy.git
git push -u origin main
```

2. cPanel → SSH Access → generate a key, add its public key to the GitHub repo as a Deploy key.
3. cPanel → Git Version Control → Create → Clone URL `git@github.com:YOU/crm-deploy.git`,
   Repository Path `repositories/crm-deploy`.

## Verify

- `https://startechgroup.ir/crm/api/ping` → `CRM OK`
