<div class="d-flex align-items-center mb-4 mt-3">
    <a href="/instances" class="btn btn-light rounded-circle shadow-sm me-3" style="width:40px;height:40px;display:flex;align-items:center;justify-content:center;">
        <i class="fas fa-arrow-left"></i>
    </a>
    <h2 class="m-0 fw-bold" style="letter-spacing:-1px;">Nova Instância</h2>
</div>

<div class="glass-card p-4 w-50">
    <div class="inner-card">
        <form action="/instances" method="POST">
            <div class="mb-4">
                <label class="form-label text-muted fw-bold" style="font-size:0.85rem;">Nome da Instância</label>
                <input type="text" name="name" class="form-control rounded-pill border-0 shadow-sm px-4 py-2" required placeholder="Ex: Atendimento Suporte">
            </div>
            <button type="submit" class="pill-btn btn-black w-100 shadow-sm">Criar</button>
        </form>
    </div>
</div>
