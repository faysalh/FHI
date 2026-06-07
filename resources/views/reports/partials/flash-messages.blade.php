@if (!empty($errorMessage))
    <div class="alert alert--error" role="alert">{{ $errorMessage }}</div>
@endif
@if (session('error'))
    <div class="alert alert--error" role="alert">{{ session('error') }}</div>
@endif
@if (session('status'))
    <div class="alert alert--success" role="status">{{ session('status') }}</div>
@endif
@if (isset($errors) && $errors->any())
    <div class="alert alert--error" role="alert">
        <ul>
            @foreach ($errors->all() as $err)
                <li>{{ $err }}</li>
            @endforeach
        </ul>
    </div>
@endif
