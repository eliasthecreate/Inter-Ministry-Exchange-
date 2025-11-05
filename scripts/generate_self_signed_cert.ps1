<#
PowerShell helper to generate a self-signed certificate for XAMPP/Apache using OpenSSL.
Run this script as Administrator.
It expects OpenSSL to be available at C:\xampp\apache\bin\openssl.exe (default XAMPP location).
Outputs placed into C:\xampp\apache\conf\ssl.crt and ...\ssl.key
#>

$ErrorActionPreference = 'Stop'

$openssl = 'C:\xampp\apache\bin\openssl.exe'
$certDir = 'C:\xampp\apache\conf\ssl.crt'
$keyDir  = 'C:\xampp\apache\conf\ssl.key'
$pfxDir  = 'C:\xampp\apache\conf\ssl.pfx'

if (-not (Test-Path $openssl)) {
    Write-Error "OpenSSL not found at $openssl. Install OpenSSL or update the path in this script (e.g. use Win64 OpenSSL or Git Bash openssl)."
    exit 1
}

# Create directories
New-Item -ItemType Directory -Path $certDir -Force | Out-Null
New-Item -ItemType Directory -Path $keyDir -Force | Out-Null
New-Item -ItemType Directory -Path $pfxDir -Force | Out-Null

# Filenames
$cn = Read-Host "Enter Common Name (CN) for certificate (e.g. localhost or your-domain.example)"
if ([string]::IsNullOrWhiteSpace($cn)) { $cn = 'localhost' }

$days = Read-Host "Days valid (default 365)"
if (-not [int]::TryParse($days, [ref]$null)) { $days = 365 }

$keyFile = Join-Path $keyDir "${cn}.key"
$csrFile = Join-Path $certDir "${cn}.csr"
$crtFile = Join-Path $certDir "${cn}.crt"
$pfxFile = Join-Path $pfxDir "${cn}.pfx"

# Generate private key
& $openssl genrsa -out $keyFile 2048
Write-Host "Generated private key: $keyFile"

# Generate CSR non-interactively using a minimal subject. You can modify the subject as needed.
$subject = "/C=US/ST=State/L=City/O=LocalDev/CN=$cn"
& $openssl req -new -key $keyFile -out $csrFile -subj $subject
Write-Host "Generated CSR: $csrFile"

# Self-sign certificate
& $openssl x509 -req -days $days -in $csrFile -signkey $keyFile -out $crtFile
Write-Host "Generated self-signed certificate: $crtFile"

# Create PKCS#12 (PFX) container (useful for importing to Windows cert store)
# Empty export password ("") will create an unprotected pfx; set a password if you need one
$pfxPassword = Read-Host "PFX export password (leave empty for no password)"
if ([string]::IsNullOrEmpty($pfxPassword)) {
    & $openssl pkcs12 -export -out $pfxFile -inkey $keyFile -in $crtFile -passout pass:
} else {
    & $openssl pkcs12 -export -out $pfxFile -inkey $keyFile -in $crtFile -passout pass:$pfxPassword
}
Write-Host "Exported PFX: $pfxFile"

Write-Host "\nNext steps:" -ForegroundColor Green
Write-Host "1) Update Apache SSL config to point to:\n   SSLCertificateFile \"$crtFile\"\n   SSLCertificateKeyFile \"$keyFile\"" -ForegroundColor Yellow
Write-Host "2) Restart Apache via the XAMPP Control Panel or run as admin: C:\\xampp\\apache\\bin\\httpd.exe -k restart" -ForegroundColor Yellow
Write-Host "3) (Optional) Import the PFX to Windows Trusted Root (requires admin) to avoid browser warnings. Example command:" -ForegroundColor Yellow
Write-Host "   Import-PfxCertificate -FilePath '$pfxFile' -CertStoreLocation Cert:\\LocalMachine\\Root -Password (ConvertTo-SecureString -String '$pfxPassword' -AsPlainText -Force)" -ForegroundColor Yellow
Write-Host "\nIf you want, open the included apache_ssl_instructions.md for a detailed walkthrough." -ForegroundColor Green

Exit 0
