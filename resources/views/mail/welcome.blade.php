<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>Your workspace is ready</title></head>
<body style="font-family: Arial, Helvetica, sans-serif; color: #1f2937; line-height: 1.5;">
    <p>Hi {{ $adminName }},</p>
    <p>Your <strong>{{ $companyName }}</strong> workspace is ready.</p>
    <p>
        Sign in any time at
        <a href="{{ $workspaceUrl }}">{{ $workspaceUrl }}</a>
    </p>
    <p>Use the email address and password you chose when you signed up.</p>
    <p style="color: #6b7280; font-size: 13px;">
        If you did not create this workspace, you can ignore this message.
    </p>
</body>
</html>
