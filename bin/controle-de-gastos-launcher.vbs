Set shell = CreateObject("WScript.Shell")
Set fileSystem = CreateObject("Scripting.FileSystemObject")
script = fileSystem.GetParentFolderName(WScript.ScriptFullName) & "\controle-de-gastos-launcher.ps1"
shell.Run "powershell.exe -NoProfile -ExecutionPolicy Bypass -File """ & script & """", 0, False
