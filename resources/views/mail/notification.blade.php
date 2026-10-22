<!DOCTYPE html>
<html>
<body style="font-family: sans-serif; line-height: 1.5; color: #1f1b16;">
    <h2 style="margin: 0 0 12px;">{{ $subjectLine }}</h2>
    @if ($bodyText)
        <p>{!! nl2br(e($bodyText)) !!}</p>
    @endif
</body>
</html>
