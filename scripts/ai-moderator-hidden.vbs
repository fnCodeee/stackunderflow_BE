Set sh = CreateObject("WScript.Shell")
sh.CurrentDirectory = "C:\Users\Raka Alfarezi\Documents\dev_learn\bk-underflow"
sh.Run """C:\laragon\bin\php\php-8.1.10-Win32-vs16-x64\php.exe"" scripts\ai_moderator_worker.php", 0, True
