Deming
Seu acesso foi criado

Olá, {{ $user->name }}.

Criamos seu acesso ao tenant {{ $tenant->name }}.

Login: {{ $user->email }}
Senha provisória: {{ $temporaryPassword }}

Por segurança, você precisará criar uma senha definitiva no primeiro acesso.

Acessar sistema:
{{ $loginUrl }}
