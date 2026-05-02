<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Chatra Support</title>
    <meta content="width=device-width, initial-scale=1, maximum-scale=1, shrink-to-fit=no" name="viewport">
    <script type="text/javascript">
        (function(d, w, c) {
            w.ChatraID = "{{ $chatra_id }}";
            var s = d.createElement('script');
            w[c] = w[c] || function() {
                (w[c].q = w[c].q || []).push(arguments);
            };
            s.async = true;
            s.src = 'https://call.chatra.io/chatra.js';
            if (d.head) d.head.appendChild(s);
        })(document, window, 'Chatra');
    </script>
    <script>
        window.ChatraIntegration = {
            name: '{{ $user->email }}',
            email: '{{ $user->email }}',
            'Class': "{{ $user->plan ? $user->plan->name : '未订阅' }}",
            'Class_Expire': '{{ $user->class_expire }}',
            'Money': '{{ $user->money }}',
            'Unused_Traffic': '{{ $user->unusedTraffic }}'
        };
        var userUUID = '{{ $user->uuid }}';
        Chatra('openChat');
    </script>
</head>
<body>

</body>
</html>
