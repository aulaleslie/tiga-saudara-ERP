<!DOCTYPE html>
<html>
<head>
    <title>Receipt</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            width: 80mm; /* Set exact width for 80mm paper */
            margin: 0 auto; /* Center content horizontally on the paper */
            padding: 0;
        }

        .receipt {
            text-align: center; /* Center the receipt content */
        }

        .line {
            border-top: 1px dashed black; /* Dashed line for separators */
            margin: 5px 0;
        }

        table {
            width: 100%; /* Use full width for the table */
            text-align: left; /* Align table text to the left */
            margin: 5px 0;
        }

        table td {
            font-size: 12px; /* Font size for table text */
        }
    </style>
</head>
<body>
<div class="receipt">
    <h2>Store Name</h2>
    <p>Address Line 1<br>Address Line 2</p>
    <div class="line"></div>
    <p>Receipt #: 12345</p>
    <p>Date: {{ now()->format('Y-m-d H:i:s') }}</p>
    <div class="line"></div>
    <table>
        <tr>
            <td>Item 1</td>
            <td>2 x $10</td>
            <td>$20</td>
        </tr>
        <tr>
            <td>Item 2</td>
            <td>1 x $15</td>
            <td>$15</td>
        </tr>
    </table>
    <div class="line"></div>
    <p>Total: $35</p>
    <p>Thank You!</p>
</div>
</body>
</html>
