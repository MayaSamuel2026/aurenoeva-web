# AURENOEVA website-native procurement upload — one-time activation

The canonical staff upload is **https://aurenoeva.com/procurement/**, inside the existing AURENOEVA website. This uses a **single private case store on the website hosting account**, with no second CRM, VPS requirement, Python runtime, or added subscription. The original customer source is retained securely outside the website document root.

## Browser activation when SSH passwords are not working

Use **Hostinger hPanel → Websites → aurenoeva.com → File Manager**.
Open `domains/aurenoeva.com`, the directory that contains `public_html`.
Create the ordinary text file `aurenoeva-activation-token.txt` **beside**, not inside, `public_html`.

Paste into the file an unpredictable 40+ character password-manager-generated code, one line only. Do not paste the code into GitHub or a support chat.
Open `https://aurenoeva.com/procurement/activate.php`; enter that exact code and set/confirm your new administrator password (at least 16 characters). The server creates and verifies the bcrypt hash in the private case directory and deletes the one-time activation file. It redirects to the ordinary procurement login.

If the token file disappears and the page redirects, activation is complete. Do not recreate the file; only one initial password setup is allowed. If activation was completed earlier, this route cannot reset its password.

## One-time password activation (Hostinger website SSH account)

The GitHub production release installs these files automatically:

- `public_html/procurement/index.php`
- `public_html/procurement/private_cases.php`
- `public_html/procurement/setup-cli.php`
- `public_html/procurement/.htaccess`

The administrator password is never supplied through chat or GitHub. The site denies access until configured.

1. Open **Hostinger hPanel → Websites → aurenoeva.com → Dashboard → SSH Access** and enable SSH if needed. Use the site's SSH terminal (or connect with an SSH client) as the **website account user**, not root on a separate VPS.
2. Run this command in the SSH terminal. It requests a password privately and does not echo it or put the value in shell history:

```bash
cd ~/domains/aurenoeva.com/public_html
read -rs -p 'New AURENOEVA procurement password (16+ characters): ' AURPASS; echo
AUR_SETUP_PASSWORD="$AURPASS" php procurement/setup-cli.php; unset AURPASS
```

3. Look for `AURENOEVA_PRIVATE_ADMIN_SETUP=PASS`. Open **https://aurenoeva.com/procurement/**, log in with your chosen password, and upload a small XLSX or CSV using an actual destination country. The case overview must report an exact private write/readback, with original equipment lines and any alternatives.

The private evidence/case directory is the hosting account's `domains/aurenoeva.com/.aurenoeva-private/`, **not** `public_html`, not GitHub, and not the separately hosted CRM. The CLI setup creates the password hash once and never overwrites an existing credential.

If the host uses a different document-root path, use Hostinger File Manager to locate `procurement/setup-cli.php` and run PHP against that absolute path. If XLSX upload reports ZIP/XML support disabled, enable `zip` and `simplexml` in **PHP Configuration** for aurenoeva.com.

## Operational boundaries

- A document upload imports customer demand and creates a private sourcing case; it does **not** publish fake catalogue inventory or prices.
- Cases deduplicate on destination country + source SHA-256. Alternative lines remain options, not extra orders.
- Legal status starts at **REVIEW_REQUIRED**. No automatic sanctions clearance, export licence, RFQ dispatch, purchase, or shipment approval is represented.
- Existing public website, enquiry email transport, and catalogue routes remain unchanged.
- The older Python/VPS worker remains a separate **source-only service** until a verified integration reads the same canonical case data. Do not operate both as independent customer upload destinations.
- Real browser authentication, permission-denied checks, private file readback, and a sample customer upload are required before declaring live acceptance.

## Reversible operations

Website files come from the existing `main → qualification → production → Hostinger` release path. Preserve and back up `.aurenoeva-private` separately; never erase its cases during a source release. The previous `production` branch version remains available for code rollback without rolling back customer data.
