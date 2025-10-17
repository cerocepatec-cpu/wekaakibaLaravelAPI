<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>WEKA AKIBA - CERO CEPATEC</title>
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: 'Nunito', sans-serif; }
        .glass { backdrop-filter: blur(12px) saturate(180%); background-color: rgba(255, 255, 255, 0.08); border-radius: 1rem; border: 1px solid rgba(255,255,255,0.1); }
    </style>
</head>
<body class="bg-gray-900 text-gray-100">

    <div class="min-h-screen flex flex-col items-center justify-start py-12 px-4">

        <!-- Header -->
        <header class="w-full max-w-7xl flex justify-between items-center mb-12">
            <h1 class="text-4xl font-bold text-blue-400">WEKA AKIBA</h1>
            <div class="flex gap-4">
                <button class="px-4 py-2 bg-blue-600 hover:bg-blue-700 rounded">Connexion</button>
                <button class="px-4 py-2 bg-gray-700 hover:bg-gray-600 rounded">S'inscrire</button>
            </div>
        </header>

        <!-- Dashboard -->
        <div class="w-full max-w-7xl grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">

            <!-- Solde -->
            <div class="glass p-6 flex flex-col items-start">
                <div class="text-sm text-gray-400">Solde du compte</div>
                <div class="text-3xl font-bold mt-2">FC {{ number_format(125480,0,'.','.') }}</div>
                <div class="mt-4 w-full flex gap-2">
                    <button class="flex-1 bg-green-600 hover:bg-green-700 rounded py-2 text-center">Déposer</button>
                    <button class="flex-1 bg-red-600 hover:bg-red-700 rounded py-2 text-center">Retirer</button>
                </div>
            </div>

            <!-- Transactions récentes -->
            <div class="glass p-6 flex flex-col">
                <div class="text-lg font-semibold mb-2">Transactions Récentes</div>
                <ul class="space-y-2">
                    <li class="flex justify-between bg-gray-800 p-2 rounded">
                        <span>Dépôt MTN Money</span><span class="text-green-400">+50,000 FC</span>
                    </li>
                    <li class="flex justify-between bg-gray-800 p-2 rounded">
                        <span>Retrait Orange Money</span><span class="text-red-400">-20,000 FC</span>
                    </li>
                    <li class="flex justify-between bg-gray-800 p-2 rounded">
                        <span>Dépôt Airtel Money</span><span class="text-green-400">+35,000 FC</span>
                    </li>
                </ul>
            </div>

            <!-- Alertes Sécurité / NAS / FBI -->
            <div class="glass p-6 flex flex-col">
                <div class="text-lg font-semibold mb-2">Alertes Sécurité</div>
                <ul class="space-y-2">
                    <li class="flex justify-between bg-red-800 p-2 rounded">
                        <span>Accès suspect détecté</span><span class="text-yellow-300">10 min ago</span>
                    </li>
                    <li class="flex justify-between bg-blue-800 p-2 rounded">
                        <span>Backup NAS terminé</span><span class="text-green-300">2h ago</span>
                    </li>
                    <li class="flex justify-between bg-purple-800 p-2 rounded">
                        <span>Nouvelle alerte FBI Cyber</span><span class="text-red-300">30 min ago</span>
                    </li>
                </ul>
            </div>

            <!-- Dépôt / Retrait rapide -->
            <div class="glass p-6 flex flex-col col-span-1 md:col-span-2 lg:col-span-3">
                <div class="text-lg font-semibold mb-4">Transaction Rapide</div>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <input type="number" placeholder="Montant" class="p-2 rounded text-gray-900">
                    <select class="p-2 rounded text-gray-900">
                        <option>MTN Money</option>
                        <option>Orange Money</option>
                        <option>Airtel Money</option>
                    </select>
                    <button class="bg-blue-500 hover:bg-blue-600 rounded py-2">Valider</button>
                </div>
            </div>

            <!-- Statistiques et Graphiques (placeholder) -->
            <div class="glass p-6 col-span-1 md:col-span-2 lg:col-span-3">
                <div class="text-lg font-semibold mb-4">Statistiques</div>
                <div class="h-64 bg-gray-800 rounded flex items-center justify-center text-gray-400">
                    Graphiques / Dashboard Analytics ici
                </div>
            </div>

        </div>

        <!-- Footer -->
        <footer class="mt-12 text-gray-500 text-sm text-center">
            &copy; {{ date('Y') }} CERO CEPATEC - WEKA AKIBA | Banque & Sécurité avancée
        </footer>

    </div>
</body>
</html>
