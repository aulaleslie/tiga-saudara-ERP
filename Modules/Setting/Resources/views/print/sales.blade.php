<!DOCTYPE html>
<html>
<head>
    <title>Sales Document</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
        }

        h1, h2, h3 {
            text-align: center;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        table th, table td {
            border: 1px solid black;
            padding: 8px;
            text-align: left;
        }
    </style>
</head>
<body>
<h1>Sales Document</h1>
<h3>Date: {{ now()->format('Y-m-d') }}</h3>
<table>
    <thead>
    <tr>
        <th>Item</th>
        <th>Quantity</th>
        <th>Price</th>
        <th>Total</th>
    </tr>
    </thead>
    <tbody>
    <tr>
        <td>Item 1</td>
        <td>2</td>
        <td>$10</td>
        <td>$20</td>
    </tr>
    <tr>
        <td>Item 2</td>
        <td>1</td>
        <td>$15</td>
        <td>$15</td>
    </tr>
    </tbody>
</table>
<h3>Total: $35</h3>
</body>
</html>
