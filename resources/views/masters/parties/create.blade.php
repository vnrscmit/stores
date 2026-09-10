<x-masters.form action="{{ route('masters.parties.store') }}">
    <h2>New Party</h2>
    <div class="filters">
        <div>
            <label>Category *</label>
            <select name="classification" required>
                <option value="">--Select Category--</option>
                @foreach ($categories as $cat)
                    <option value="{{ $cat }}" @selected(old('classification') === $cat)>{{ $cat }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label>Business Name *</label>
            <input type="text" name="business_name" value="{{ old('business_name') }}" maxlength="255" required>
        </div>
        <div><label>Contact</label><input type="text" name="contact" value="{{ old('contact') }}" maxlength="100"></div>
        <div><label>Address</label><input type="text" name="address" value="{{ old('address') }}"></div>
        <div><label>City</label><input type="text" name="city" value="{{ old('city') }}" maxlength="100"></div>
        <div><label>Country *</label>
            <input type="text" name="country" value="{{ old('country', 'India') }}" maxlength="200" required>
        </div>
        <div><label>State @if(old('country', 'India') === 'India')* @endif</label><input type="text" name="state" value="{{ old('state') }}" maxlength="100"></div>
        <div><label>PIN</label><input type="number" name="pin" value="{{ old('pin') }}"></div>
        <div><label>Mobile</label><input type="number" name="mob" value="{{ old('mob') }}"></div>
        <div><label>STD</label><input type="number" name="std" value="{{ old('std') }}"></div>
        <div><label>Phone</label><input type="text" name="phone" value="{{ old('phone') }}" maxlength="20"></div>
        <div><label>TIN</label><input type="text" name="tin" value="{{ old('tin') }}" maxlength="100"></div>
        <div><label>CST</label><input type="text" name="cst" value="{{ old('cst') }}" maxlength="100"></div>
        <div><label>PAN</label><input type="text" name="pan" value="{{ old('pan') }}" maxlength="100"></div>
        <div><label>Product</label><input type="text" name="product" value="{{ old('product') }}" maxlength="100"></div>
    </div>
</x-masters.form>
