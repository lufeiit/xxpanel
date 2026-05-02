<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Crisp Support</title>
    <meta content="width=device-width, initial-scale=1, maximum-scale=1, shrink-to-fit=no" name="viewport">
    <script type="text/javascript">
        window.$crisp = [];
        window.CRISP_WEBSITE_ID = "{{ $crisp_id }}";
        CRISP_TOKEN_ID = '{{ $user->uuid }}';
        (function () {
            d = document;
            s = d.createElement("script");
            s.src = "https://client.crisp.chat/l.js";
            s.async = 1;
            d.getElementsByTagName("head")[0].appendChild(s);
            s.onload = function() {
                setTimeout(function() {
                    var chatbox = document.querySelector(".cc-1hqb");
                    if (chatbox) {
                        chatbox.style.setProperty('width', '95%', 'important');
                        chatbox.style.setProperty('height', '90%', 'important');
                    }
                }, 3000); // Adjust timing as necessary
            }
        })();
    </script>
    <script>
        $crisp.push(["do", "chat:show"])
        $crisp.push(["do", "chat:open"])
        $crisp.push(
            ["set", "user:email", "{{ $user->email }}"],
            ["set", "user:nickname", "{{ $user->email }}"]
        );
        $crisp.push(
            ["set", "session:data",
                [
                    [
                        ["ID", "{{ $user->id }}"],
                        ["VIP", "{{ $user->plan ? $user->plan->name : '未订阅' }}"],
                        ["VIP_Time", "{{ $user->class_expire }}"],
                        ["Money", "¥ " + "{{ $user->money }}"],
                        ["Traffic", '{{ $user->unusedTraffic }}'],
                        ["Reg_Time", '{{ $user->reg_date }}'],
                        ["Update_Time", '{{ date("Y-m-d H:i:s") }}'],
                    ]
                ]
            ]
        );
    </script>
</head>
<body>

</body>
</html>
