<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - We Chirp</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100">
    <div class="min-h-screen flex items-center justify-center">
        <div class="bg-white p-8 rounded-lg shadow-md w-96">
            <h1 class="text-2xl font-bold text-center text-blue-600 mb-6">We Chirp</h1>
            
            <form>
                <div class="mb-4">
                    <input type="email" placeholder="Email" 
                        class="w-full px-3 py-2 border border-gray-300 rounded focus:outline-none focus:border-blue-500">
                </div>
                
                <div class="mb-4">
                    <input type="password" placeholder="Password" 
                        class="w-full px-3 py-2 border border-gray-300 rounded focus:outline-none focus:border-blue-500">
                </div>
                
                <button type="submit" 
                    class="w-full bg-blue-500 text-white py-2 rounded hover:bg-blue-600 transition">
                    Login
                </button>
            </form>
            
            <p class="text-center text-gray-600 text-sm mt-4">
                New to We Chirp? <a href="#" class="text-blue-500">Sign up</a>
            </p>
        </div>
    </div>
</body>
</html>