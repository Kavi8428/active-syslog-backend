

<!DOCTYPE html>
<html>
<head>
    <title>Add Collector</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .form-container { max-width: 400px; padding: 20px; border: 1px solid #ccc; border-radius: 5px; }
        label { display: block; margin: 10px 0 5px; }
        input { width: 100%; padding: 8px; margin-bottom: 10px; }
        button { padding: 10px 20px; background-color: #4CAF50; color: white; border: none; cursor: pointer; }
        button:hover { background-color: #45a049; }
        .message { margin-top: 10px; color: green; }
        .error { color: red; }
    </style>
</head>
<body>
    <div class="form-container">
        <h2>Add New Collector</h2>
        <form id="collectorForm">
            <label for="ip">Collector IP:</label>
            <input type="text" id="ip" name="ip" required placeholder="e.g., 192.168.0.40">

            <label for="username">Username:</label>
            <input type="text" id="username" name="username" required placeholder="e.g., logger_sync">

            <label for="password">Password:</label>
            <input type="password" id="password" name="password" required>

            <label for="database_name">Database Name:</label>
            <input type="text" id="database_name" name="database_name" placeholder="e.g., syslog_db" value="syslog_db">

            
            <button type="submit">Add Collector</button>
        </form>
        <div id="responseMessage"></div>
    </div>

    <script>
    document.getElementById('collectorForm').addEventListener('submit', async function(event) {
        event.preventDefault(); // Prevent default form submission

        const formData = new FormData();
        formData.append('ip', document.getElementById('ip').value);
        formData.append('username', document.getElementById('username').value);
        formData.append('password', document.getElementById('password').value);
        formData.append('database_name', document.getElementById('database_name').value);

        try {
            const response = await fetch('../../api/insert_collector.php', {
                method: 'POST',
                body: formData
            });

            const result = await response.text(); // Expect plain text response
            const responseMessage = document.getElementById('responseMessage');
            responseMessage.textContent = result;
            responseMessage.className = result.includes('successfully') ? 'message' : 'error';
        } catch (error) {
            const responseMessage = document.getElementById('responseMessage');
            responseMessage.textContent = "Error: " + error.message;
            responseMessage.className = 'error';
        }
    });
</script>
</body>
</html>