@extends('reports.layouts.app')
@section('title', 'Accounting — Receipts')

@section('content')
<header class="page-header"><h1>Accounting — Receipts</h1></header>

@include('reports.partials.flash-messages')

@include('reports.accounting.partials.receipts', [
    'receiptBookletsAssigned' => $receiptBookletsAssigned ?? [],
    'receiptBookletsUnassigned' => $receiptBookletsUnassigned ?? [],
    'receiptBookletsReturned' => $receiptBookletsReturned ?? [],
    'drivers' => $drivers ?? [],
])
@endsection
