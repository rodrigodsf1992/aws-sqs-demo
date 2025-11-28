@extends('layout.app-layout')

@section('content')

<div class="container pt-3" style="justify-items: center;">
    <div class="row w-100">
        <div class="col d-flex justify-content-end">
            <div class="form-floating">
                <input type="number" class="form-control" name="qtd" id="qtd" value="1" placeholder="Quantidade" />
                <label for="qtd">Qtd:</label>
            </div>
            <button class="btn btn-success ms-2" onclick="sendOrder()">Comprar</button>
        </div>
        <div class="col-4 d-flex justify-content-center">
            <button class="btn btn-primary me-2" onclick="getOrder()">Processar Pedidos</button>
        </div>
        <div class="col-4 d-flex">
            <button class="btn btn-danger me-2" onclick="deleteOrder()">Deletar Pedidos</button>
        </div>
    </div>
    <div class="row pt-2 w-100">
        <div class="col">
            <div id="message" class="d-none p-2 border"></div>
        </div>
    </div>
</div>

<script>
    let url = `/api/order`;

    function sendOrder() {
        reset();

        axios.post(url, {
            qtd: document.getElementById("qtd").value
        }).then(ok).then(function(ret) {
            document.querySelector('#message').innerHTML += "<br>Failed to send AWS SQS: " + (ret.data.failed.join(', ') || "Não ocorreram falhas");
        }).catch(error);
    }

    function deleteOrder() {
        reset();

        axios.delete(url).then(ok).catch(error);
    }

    function getOrder() {
        reset();

        axios.get(url).then(ok).catch(error);
    }

    function ok(ret) {
        document.querySelector('#message').innerHTML = ret.data.message;
        document.querySelector('#message').classList.remove('d-none');
        document.querySelector('#message').classList.add('bg-light','text-success');
        return ret;
    }

    function reset() {
        document.querySelector('#message').classList.remove('bg-light','bg-error','text-white','text-success');
        document.querySelector('#message').classList.add('d-none');
        document.querySelector('#message').innerHTML = "";
    }

    function error(error) {
        document.querySelector('#message').innerHTML = error.response.data.message;
        document.querySelector('#message').classList.remove('d-none');
        document.querySelector('#message').classList.add('bg-error','text-white');
    }
</script>

@endsection